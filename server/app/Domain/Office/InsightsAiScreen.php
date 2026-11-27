<?php

namespace App\Domain\Office;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\Copilot;
use App\Domain\Analytics\InsightAlerts;
use App\Domain\Support\Actor;
use App\Filament\Pages\Copilot as CopilotPage;
use App\Filament\Resources\AiAlerts\AiAlertResource;
use App\Filament\Resources\AiRequests\AiRequestResource;
use App\Filament\Support\Erp;
use App\Models\AiAlert;
use App\Models\AiRequest;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;

/**
 * Insights in the new back office (part 29 of the rewrite), as the current pages: Ask your business (quick questions and
 * the chat), Alerts (from the nightly insights) and the AI usage log. Same queries and rules: the Copilot and
 * InsightAlerts services (InsightsAiController).
 */
final class InsightsAiScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const SEVERITY = ['high' => 'High', 'warning' => 'Warning', 'info' => 'Info'];

    public const SEVERITY_TONE = ['high' => 'danger', 'warning' => 'warning', 'info' => 'subtle'];

    public function __construct(private readonly User $user) {}

    private static function choices(array $map): array
    {
        return collect($map)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private static function page(array $p): array
    {
        return [in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25, max(1, (int) ($p['page'] ?? 1))];
    }

    public static function canAsk(): bool
    {
        return CopilotPage::canAccess();
    }

    public static function canAlerts(): bool
    {
        return AiAlertResource::canViewAny();
    }

    public static function canUsage(): bool
    {
        return AiRequestResource::canViewAny();
    }

    // -------------------------------------------------------------------------------------------- Ask your business

    /** Whether AI answers are available (else why), and the quick questions this person may ask. */
    public function ask(): array
    {
        $quick = app(Copilot::class)->quickFor(Actor::user($this->user, 'web'));

        return ['status' => app(AiGateway::class)->unavailableReason($this->user->tenant_id),
            'quick' => collect($quick)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all()];
    }

    // -------------------------------------------------------------------------------------------- Alerts

    public function alertsQuery(): Builder
    {
        return AiAlertResource::getEloquentQuery();
    }

    /** @param  array<string, mixed>  $p  q, status (open by default; all), kind, severity, page, per */
    public function alerts(array $p): array
    {
        $q = $this->alertsQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where('ai_alerts.title', 'ilike', "%{$s}%");
        }
        $status = (string) ($p['status'] ?? 'open');
        if (isset(AiAlert::STATUSES[$status])) {
            $q->where('ai_alerts.status', $status);
        } else {
            $status = 'all';
        }
        $kinds = Erp::canAnywhere('insights.loss_prevention') ? InsightAlerts::KINDS : array_diff_key(InsightAlerts::KINDS, ['loss' => 1]);
        if (isset($kinds[$p['kind'] ?? ''])) {
            $q->where('ai_alerts.kind', $p['kind']);
        }
        if (isset(self::SEVERITY[$p['severity'] ?? ''])) {
            $q->where('ai_alerts.severity', $p['severity']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();

        return ['total' => $total, 'page' => $page, 'per' => $per, 'status' => $status, 'statuses' => self::choices(AiAlert::STATUSES), 'kinds' => self::choices($kinds),
            'severities' => self::choices(self::SEVERITY), 'can_refresh' => Erp::companyWide('insights.view'),
            'rows' => $q->orderByDesc('ai_alerts.updated_at')->forPage($page, $per)->get()->map(fn (AiAlert $a) => ['id' => $a->id,
                'severity' => self::SEVERITY[$a->severity] ?? $a->severity, 'tone' => self::SEVERITY_TONE[$a->severity] ?? 'subtle',
                'kind' => InsightAlerts::KINDS[$a->kind] ?? $a->kind, 'title' => $a->title, 'detail' => $a->detail, 'seen' => $a->updated_at?->diffForHumans(),
                'status' => $a->status, 'status_label' => AiAlert::STATUSES[$a->status] ?? $a->status,
                'handled' => $a->handled_by_name ? trim("{$a->handled_by_name} {$a->handled_note}") : null, 'open' => $a->status === 'open'])->all()];
    }

    // -------------------------------------------------------------------------------------------- AI usage log

    /** @param  array<string, mixed>  $p  q, feature, status, page, per */
    public function usage(array $p): array
    {
        $q = AiRequestResource::getEloquentQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('user_name', 'ilike', "%{$s}%")->orWhere('prompt', 'ilike', "%{$s}%"));
        }
        if (isset(AiGateway::FEATURES[$p['feature'] ?? ''])) {
            $q->where('feature', $p['feature']);
        }
        if (in_array($p['status'] ?? '', ['ok', 'error'], true)) {
            $q->where('status', $p['status']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $tenant = $this->user->tenant_id;
        $gateway = app(AiGateway::class);

        return ['total' => $total, 'page' => $page, 'per' => $per, 'features' => self::choices(AiGateway::FEATURES),
            'spent' => AiRequestResource::usd($gateway->spentThisMonth($tenant)), 'cap' => 'US$ '.(int) app(Settings::class)->get('ai.monthly_cap_usd', $tenant),
            'rows' => $q->orderByDesc('created_at')->forPage($page, $per)->get()->map(fn (AiRequest $r) => ['id' => $r->id, 'when' => $r->created_at?->toIso8601String(),
                'who' => $r->user_name, 'feature' => AiGateway::FEATURES[$r->feature] ?? $r->feature, 'model' => "{$r->provider} · {$r->model}",
                'question' => mb_strimwidth((string) $r->prompt, 0, 60, '…'), 'cost' => AiRequestResource::usd((int) $r->cost_micro_usd), 'status' => $r->status])->all()];
    }

    public function request(AiRequest $r): array
    {
        return ['id' => $r->id, 'when' => $r->created_at?->toIso8601String(), 'who' => $r->user_name, 'feature' => AiGateway::FEATURES[$r->feature] ?? $r->feature,
            'model' => "{$r->provider} · {$r->model}", 'tokens' => "{$r->tokens_in} / {$r->tokens_out}", 'cost' => AiRequestResource::usd((int) $r->cost_micro_usd),
            'status' => $r->status, 'error' => $r->error, 'took' => number_format(((int) $r->duration_ms) / 1000, 1).' s', 'prompt' => (string) $r->prompt,
            'response' => $r->response, 'tools' => collect($r->tools)->map(fn ($t) => $t['name'].' '.json_encode($t['arguments']))->values()->all()];
    }
}
