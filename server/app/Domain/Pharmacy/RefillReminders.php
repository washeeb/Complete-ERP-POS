<?php

namespace App\Domain\Pharmacy;

use App\Domain\Messaging\Messenger;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Industry;
use App\Models\LegalEntity;
use App\Models\RefillReminder;
use App\Models\Tenant;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Refill reminders (owner request 2026-10-23). A medicine taken every day carries how much a patient uses a day
 * (products.daily_use, in base units). Each sale of it to a named customer becomes a reminder: the quantity sold lasts
 * so many days, and a few days before it runs out the customer gets one WhatsApp (or SMS) message listing the medicines
 * due — only with their consent to be texted (Customers → consent). Buying the medicine again replaces the old reminder.
 *
 * Runs on head office (or a single server) only: branch sales reach it by sync, so a reminder is never sent twice.
 */
final class RefillReminders
{
    /** Shortest and longest time a sale can last, so a wrong daily figure cannot remind tomorrow or in three years. */
    private const MIN_DAYS = 3;

    private const MAX_DAYS = 365;

    public function __construct(private readonly Settings $settings, private readonly Messenger $messenger, private readonly Recorder $recorder) {}

    /** Make reminders for new sales, then send those due. @return array{made: int, sent: int} */
    public function run(?Carbon $now = null): array
    {
        if (config('erp.node.role') === 'branch') {
            return ['made' => 0, 'sent' => 0];
        }
        $made = $sent = 0;
        foreach (Tenant::query()->get() as $tenant) {
            if (! $this->settings->get('refill.reminders', $tenant->id) || Tenant::isPractice($tenant->id)) {
                continue;
            }
            $made += $this->collect($tenant, $now);
            $sent += $this->sendDue($tenant, $now);
        }

        return ['made' => $made, 'sent' => $sent];
    }

    /** Reminders for sale lines of daily medicines to named customers in the last 60 days that have none yet. */
    public function collect(Tenant $tenant, ?Carbon $now = null): int
    {
        $today = ($now ?? now())->copy()->setTimezone($tenant->timezone ?: 'UTC')->startOfDay();
        $before = max(0, (int) $this->settings->get('refill.days_before', $tenant->id));
        $rows = DB::table('sale_lines')->join('sales', 'sales.id', '=', 'sale_lines.sale_id')->join('products', 'products.id', '=', 'sale_lines.product_id')
            ->leftJoin('refill_reminders', 'refill_reminders.sale_line_id', '=', 'sale_lines.id')
            ->where('sales.tenant_id', $tenant->id)->where('sales.status', 'posted')->whereNotNull('sales.customer_id')
            ->where('products.daily_use', '>', 0)->where('sale_lines.qty', '>', 0)->whereNull('refill_reminders.id')
            ->where('sales.occurred_at', '>=', $today->copy()->subDays(60)->utc())
            ->orderBy('sales.occurred_at')->limit(500)
            ->get(['sale_lines.id as line_id', 'sale_lines.sale_id', 'sale_lines.product_id', 'sale_lines.name', 'sale_lines.qty', 'sale_lines.factor',
                'sales.branch_id', 'sales.customer_id', 'sales.occurred_at', 'products.daily_use']);
        foreach ($rows as $r) {
            $qty = round((float) $r->qty * (float) $r->factor, 3);
            $days = (int) min(self::MAX_DAYS, max(self::MIN_DAYS, floor($qty / (float) $r->daily_use)));
            $runsOut = Carbon::parse($r->occurred_at)->setTimezone($tenant->timezone ?: 'UTC')->startOfDay()->addDays($days);
            $remindOn = $runsOut->copy()->subDays($before);
            DB::transaction(function () use ($tenant, $r, $qty, $runsOut, $remindOn, $today) {
                // Bought again: the earlier reminder for the same medicine is no longer needed.
                RefillReminder::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('customer_id', $r->customer_id)
                    ->where('product_id', $r->product_id)->where('status', 'waiting')->update(['status' => 'bought', 'updated_at' => now()]);
                RefillReminder::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'branch_id' => $r->branch_id, 'customer_id' => $r->customer_id,
                    'product_id' => $r->product_id, 'sale_id' => $r->sale_id, 'sale_line_id' => $r->line_id, 'product_name' => mb_substr($r->name, 0, 160),
                    'qty_base' => $qty, 'runs_out_on' => $runsOut->toDateString(), 'remind_on' => $remindOn->toDateString(),
                    'status' => $runsOut->lt($today) ? 'past' : 'waiting']);
            });
        }

        return $rows->count();
    }

    /** One message per customer for the reminders due today, once the day's sending time has passed. */
    public function sendDue(Tenant $tenant, ?Carbon $now = null): int
    {
        $local = ($now ?? now())->copy()->setTimezone($tenant->timezone ?: 'UTC');
        $at = (string) $this->settings->get('refill.send_at', $tenant->id);
        if ($local->format('H:i') < (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $at) ? $at : '09:00')) {
            return 0;
        }
        $due = RefillReminder::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', 'waiting')
            ->where('remind_on', '<=', $local->toDateString())->orderBy('runs_out_on')->limit(500)->get()->groupBy('customer_id');
        $sent = 0;
        foreach ($due as $customerId => $reminders) {
            $sent += $this->send($tenant, Customer::withoutGlobalScopes()->find($customerId), $reminders->all(), 'Refill reminders') ? 1 : 0;
        }

        return $sent;
    }

    /** Send now from the back office (Customers → Refill reminders), audited. */
    public function sendNow(Actor $actor, RefillReminder $r): void
    {
        if ($r->status !== 'waiting') {
            throw new DomainException('Only a waiting reminder can be sent.');
        }
        $tenant = Tenant::findOrFail($r->tenant_id);
        if (! $this->send($tenant, Customer::withoutGlobalScopes()->find($r->customer_id), [$r], $actor->name)) {
            throw new DomainException('Not sent: the customer has no mobile number, or has not agreed to WhatsApp or SMS messages (open the customer → consent).');
        }
        $this->recorder->transaction(fn () => $this->recorder->audit->record($actor, 'refill_reminder.sent', $r, ['status' => ['waiting', 'sent']], null, $r->branch_id));
    }

    public function cancel(Actor $actor, RefillReminder $r): RefillReminder
    {
        if ($r->status !== 'waiting') {
            throw new DomainException('Only a waiting reminder can be cancelled.');
        }
        $r->forceFill(['status' => 'cancelled', 'closed_by_name' => mb_substr($actor->name, 0, 120)]);

        return $this->recorder->transaction(function () use ($actor, $r) {
            $this->recorder->audit->record($actor, 'refill_reminder.cancelled', $r, Recorder::diff($r), null, $r->branch_id);
            $r->save();

            return $r;
        });
    }

    /** @param list<RefillReminder> $reminders */
    private function send(Tenant $tenant, ?Customer $customer, array $reminders, string $by): bool
    {
        $consent = $customer && ($customer->hasConsent('whatsapp') || $customer->hasConsent('sms'));
        $phone = $consent ? ($customer->phone ?? null) : null;
        $messages = [];
        if ($phone) {
            $branch = Branch::withoutGlobalScopes()->find($reminders[0]->branch_id);
            // The trading company of the branch (as on its receipts), not the group.
            $entity = $branch?->legal_entity_id ? LegalEntity::withoutGlobalScopes()->find($branch->legal_entity_id)
                : ($branch?->industry_id ? LegalEntity::withoutGlobalScopes()->find(Industry::withoutGlobalScopes()->whereKey($branch->industry_id)->value('legal_entity_id')) : null);
            $first = collect($reminders)->min('runs_out_on');
            $vars = ['customer' => $customer->name, 'company' => $entity?->name ?? $tenant->name, 'branch' => $branch?->name ?? '',
                'phone' => $branch?->phone ?? '', 'medicines' => collect($reminders)->pluck('product_name')->unique()->implode(', '),
                'runs_out' => Carbon::parse($first)->format('j M')];
            $key = 'refill:'.$customer->id.':'.$first->toDateString();
            try {
                $messages = Cache::add($key, true, now()->addDays(2)) // never twice for the same day, even when two runs overlap
                    ? $this->messenger->toCustomer($tenant->id, 'refill_reminder', $phone, null, $vars, 'REFILL', $by) : [];
            } catch (Throwable $e) {
                report($e);
                Cache::forget($key);

                return false; // tried again at the next run
            }
        }
        $channel = $messages[0]->channel ?? null;
        foreach ($reminders as $r) {
            $r->forceFill($channel ? ['status' => 'sent', 'sent_at' => now(), 'channel' => $channel] : ['status' => 'no_contact'])->save();
        }

        return $channel !== null;
    }
}
