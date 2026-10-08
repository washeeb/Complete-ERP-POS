<?php

namespace App\Domain\Ai;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Tenant;
use App\Security\Authorizer;
use App\Support\Settings;

/**
 * "Ask your business". The model chooses among the read-only report tools (CopilotTools), the tools run with the
 * person's own permissions, and the model words the answer from their results only. The tables themselves are shown
 * with the answer, so every figure can be checked.
 *
 * Privacy: with a cloud model, names are masked both ways; when a question needs customer or staff details and
 * "keep personal data on your server" is on, the rest of the conversation moves to the local model — or, without one,
 * the tables are shown without AI wording.
 */
final class Copilot
{
    public const MAX_STEPS = 5;

    /** Questions that work without any AI model: one report each. */
    public const QUICK = [
        'sales_month_branch' => ['Sales this month by branch', 'sales_summary', ['group_by' => 'branch']],
        'sales_month_day' => ['Sales this month by day', 'sales_summary', ['group_by' => 'day']],
        'top_products' => ['Best-selling products this month', 'sales_summary', ['group_by' => 'product']],
        'reorder' => ['What will run out soon?', 'reorder_suggestions', []],
        'stock_health' => ['Dead stock, overstock and expiry', 'stock_health', []],
        'alerts' => ['Open alerts', 'open_alerts', []],
        'cash' => ['Money now', 'cash_position', []],
        'cash_flow' => ['Cash-flow forecast', 'cash_flow_forecast', []],
        'customers' => ['Who owes us?', 'customer_balances', []],
        'suppliers' => ['What do we owe suppliers?', 'supplier_payments_due', []],
        'margins' => ['Low-margin products', 'margins', []],
        'segments' => ['Customer segments', 'customer_segments', []],
    ];

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly CopilotTools $tools,
        private readonly Authorizer $authz,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $history  earlier questions and answers in this chat
     * @return array{answer: string, tables: list<array>, provider: string, notice: ?string}
     */
    public function ask(Actor $actor, string $question, array $history = []): array
    {
        $this->authorize($actor);
        $question = trim(mb_substr($question, 0, 1000));
        if ($question === '') {
            throw new DomainException('Type a question.');
        }
        $tenant = Tenant::findOrFail($actor->tenantId);
        $provider = $this->gateway->provider($actor->tenantId);
        $masker = new Masker($provider->isCloud());
        $definitions = $this->tools->definitions($actor->user);
        $messages = array_map(fn ($m) => ['role' => $m['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $masker->mask((string) $m['content'])], array_slice($history, -6));
        $messages[] = ['role' => 'user', 'content' => $masker->mask($question)];
        $tables = [];
        $notice = null;

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            try {
                $reply = $this->gateway->chat($actor, 'copilot', $provider, $this->system($tenant), $messages, $definitions);
            } catch (AiUnavailable $e) {
                if ($tables) { // the figures are there; only the wording is missing
                    return ['answer' => '', 'tables' => $this->display($tables, $masker), 'provider' => $provider->name(), 'notice' => $notice ?? $e->getMessage()];
                }

                throw $e;
            }
            if (! $reply->toolCalls) {
                return ['answer' => $masker->unmask(trim($reply->text)), 'tables' => $this->display($tables, $masker), 'provider' => $provider->name(), 'notice' => $notice];
            }
            $messages[] = ['role' => 'assistant', 'content' => $reply->text, 'tool_calls' => $reply->toolCalls];
            foreach ($reply->toolCalls as $call) {
                if ($provider->isCloud() && $this->tools->isPersonal($call['name']) && $this->settings->get('ai.personal_data_local', $actor->tenantId)) {
                    // Personal details stay here: continue on the local model (or show the table without AI wording).
                    $provider = $this->gateway->provider($actor->tenantId, 'text', personal: true); // same masker: placeholders stay consistent
                    $notice = 'Customer and staff details were kept on your server.';
                }
                try {
                    $table = $this->tools->run($actor->user, $masker, $call['name'], (array) $call['arguments']);
                    $tables[] = $table;
                    $result = json_encode(['title' => $table['title'], 'columns' => $table['columns'], 'rows' => $table['rows'], 'note' => $table['note'] ?? null], JSON_UNESCAPED_UNICODE);
                } catch (DomainException $e) {
                    $result = json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                }
                $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'name' => $call['name'], 'content' => $result];
            }
        }

        return ['answer' => 'That needed more steps than allowed; here is what was found.', 'tables' => $this->display($tables, $masker), 'provider' => $provider->name(), 'notice' => $notice];
    }

    /** A ready-made question answered straight from one report — no AI involved. */
    public function quick(Actor $actor, string $key): array
    {
        $this->authorize($actor);
        [, $tool, $args] = self::QUICK[$key] ?? throw new DomainException('Unknown question.');

        return $this->tools->run($actor->user, new Masker(false), $tool, $args);
    }

    /** Quick questions this person can use (their permissions decide which reports exist for them). */
    public function quickFor(Actor $actor): array
    {
        $names = array_column($this->tools->definitions($actor->user), 'name');

        return array_filter(array_map(fn ($q) => $q[0], self::QUICK), fn ($k) => in_array(self::QUICK[$k][1], $names, true), ARRAY_FILTER_USE_KEY);
    }

    private function authorize(Actor $actor): void
    {
        if (! $actor->user || ! $this->authz->allowsAnywhere($actor->user, 'ai.copilot')) {
            throw new AccessDenied;
        }
    }

    private function system(Tenant $tenant): string
    {
        $today = now($tenant->timezone ?: 'UTC')->toDateString();

        return "You are the business copilot of {$tenant->name}, a business in Ghana using the Synclytics ERP. Today is {$today}. Money is in {$tenant->currency_code}.\n"
            ."Answer only from the results of the tools; call a tool for every figure and never invent or estimate numbers. If the tools cannot answer, say so and suggest which report to open.\n"
            ."Keep answers short: a sentence or a few bullets, say which period the figures cover, and point out anything unusual. Placeholders such as [Customer 2] or [Staff 1] stand for real names: use them exactly as given.\n"
            .'You cannot change anything: if asked to post, approve, order or pay, explain where in the system the person can do it.';
    }

    /** Tables as the person sees them: placeholders back to names. */
    private function display(array $tables, Masker $masker): array
    {
        return array_map(fn ($t) => ['title' => $t['title'], 'columns' => $t['columns'], 'note' => $t['note'] ?? null,
            'rows' => array_map(fn ($row) => array_map(fn ($v) => $masker->unmask((string) $v), $row), $t['rows'])], $tables);
    }
}
