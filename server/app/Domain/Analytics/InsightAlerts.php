<?php

namespace App\Domain\Analytics;

use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\AiAlert;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns the insights into alerts someone should look at (run nightly, or "Refresh" on the Alerts page): stock about to
 * run out, stock that will expire unsold, products sold below cost or under the margin target, cashiers with an unusual
 * pattern, and a week the cash forecast goes below zero. One open alert per kind and subject; alerts whose condition
 * has gone are closed as resolved.
 */
final class InsightAlerts
{
    public const KINDS = ['stockout' => 'Running out', 'expiry' => 'Will expire unsold', 'margin' => 'Low margin', 'loss' => 'Loss prevention',
        'cash' => 'Cash shortfall'];

    public function __construct(
        private readonly DemandForecaster $forecasts,
        private readonly StockHealth $stock,
        private readonly PricingAdvisor $pricing,
        private readonly LossPrevention $loss,
        private readonly CashFlowForecaster $cash,
        private readonly Recorder $recorder,
    ) {}

    /** @return array<string, int> open alerts per kind after the run */
    public function run(string $tenantId, ?CarbonImmutable $today = null): array
    {
        $tz = Tenant::find($tenantId)?->timezone ?: 'UTC';
        $today ??= CarbonImmutable::now($tz)->startOfDay();
        $found = [];
        $warehouses = DB::table('warehouses')->where('tenant_id', $tenantId)->where('is_active', true)->get(['id', 'name', 'branch_id']);

        foreach ($warehouses as $w) {
            foreach ($this->forecasts->forWarehouse($w->id, null, 28, $today) as $f) {
                if ($f['days_cover'] !== null && $f['avg_daily'] > 0 && $f['days_cover'] < $f['lead_days']) {
                    $found[] = ['kind' => 'stockout', 'severity' => $f['days_cover'] < 2 ? 'high' : 'warning', 'branch_id' => $w->branch_id,
                        'subject_type' => 'product', 'subject_id' => $f['product_id'], 'fingerprint' => "stockout:{$w->id}:{$f['product_id']}",
                        'title' => "{$f['name']} runs out in about ".max(0, (int) floor($f['days_cover']))." days at {$w->name}",
                        'detail' => "On hand {$f['on_hand']}, selling about {$f['avg_daily']} a day; supplier lead time {$f['lead_days']} days. Suggested order: {$f['suggested_order']}.",
                        'data' => $f];
                }
            }
        }
        $health = $this->stock->report($tenantId, $warehouses->pluck('id')->all(), $today);
        foreach ($health['expiry'] as $e) {
            $found[] = ['kind' => 'expiry', 'severity' => $e['days_left'] <= 7 ? 'high' : 'warning', 'branch_id' => $warehouses->firstWhere('id', $e['warehouse_id'])?->branch_id, 'subject_type' => 'product', 'subject_id' => $e['product_id'],
                'fingerprint' => "expiry:{$e['product_id']}:{$e['batch_no']}:{$e['warehouse']}",
                'title' => "{$e['at_risk']} × {$e['name']} (batch {$e['batch_no']}) may expire unsold at {$e['warehouse']}",
                'detail' => "Expires {$e['expires_on']} ({$e['days_left']} days). Suggested markdown {$e['markdown_pct']} %, or transfer to a branch that sells it faster.", 'data' => $e];
        }
        foreach ($this->pricing->margins($tenantId) as $m) {
            $found[] = ['kind' => 'margin', 'severity' => $m['below_cost'] ? 'high' : 'info', 'branch_id' => null, 'subject_type' => 'product', 'subject_id' => $m['product_id'],
                'fingerprint' => "margin:{$m['product_id']}",
                'title' => $m['below_cost'] ? "{$m['name']} sells below cost" : "{$m['name']} margin is {$m['margin_pct']} %",
                'detail' => 'Price before tax vs average cost. Price for the target margin: '.number_format($m['price_for_target_minor'] / 100, 2).'.', 'data' => $m];
        }
        foreach ($this->loss->cashiers($tenantId, $today->subDays(30), $today->addDay()) as $c) {
            if (($c['risk'] ?? 0) >= 50) {
                $found[] = ['kind' => 'loss', 'severity' => $c['risk'] >= 75 ? 'high' : 'warning', 'branch_id' => null, 'subject_type' => 'user', 'subject_id' => $c['user_id'],
                    'fingerprint' => "loss:{$c['user_id']}", 'title' => "Unusual till pattern: {$c['name']} (risk {$c['risk']})",
                    'detail' => 'Last 30 days: '.implode(', ', $c['flags']).'. Compare receipts and CCTV before drawing conclusions.', 'data' => $c];
            }
        }
        $cash = $this->cash->forecast($tenantId, 8, $today);
        if ($cash['lowest_minor'] < 0) {
            $found[] = ['kind' => 'cash', 'severity' => 'high', 'branch_id' => null, 'subject_type' => null, 'subject_id' => null, 'fingerprint' => 'cash:shortfall',
                'title' => 'Cash forecast goes below zero in the week of '.$cash['lowest_week'],
                'detail' => 'Lowest forecast balance: '.number_format($cash['lowest_minor'] / 100, 2).'. Chase customer payments or reschedule supplier payments.', 'data' => ['lowest_minor' => $cash['lowest_minor']]];
        }

        $actor = Actor::system($tenantId, 'Insights', 'system');
        $this->recorder->transaction(function () use ($tenantId, $found, $actor) {
            $seen = [];
            foreach ($found as $a) {
                $seen[] = $a['fingerprint'];
                $open = AiAlert::where('tenant_id', $tenantId)->where('fingerprint', $a['fingerprint'])->where('status', 'open')->first();
                if ($open) {
                    $open->forceFill(array_diff_key($a, ['fingerprint' => 1]))->save(); // refresh the figures
                } elseif (! AiAlert::where('tenant_id', $tenantId)->where('fingerprint', $a['fingerprint'])->where('status', 'dismissed')
                    ->where('handled_at', '>', now()->subDays(30))->exists()) { // dismissed: quiet for 30 days
                    (new AiAlert)->forceFill($a + ['tenant_id' => $tenantId, 'status' => 'open'])->save();
                }
            }
            AiAlert::where('tenant_id', $tenantId)->where('status', 'open')->whereNotIn('fingerprint', $seen ?: [''])
                ->update(['status' => 'resolved', 'handled_by_name' => $actor->name, 'handled_at' => now(), 'updated_at' => now()]);
        });

        return AiAlert::where('tenant_id', $tenantId)->where('status', 'open')->groupBy('kind')->selectRaw('kind, COUNT(*) AS n')->pluck('n', 'kind')->map(fn ($n) => (int) $n)->all();
    }

    /** Someone has looked: dismiss (not a problem) or mark resolved (dealt with). */
    public function handle(Actor $actor, AiAlert $alert, string $status, ?string $note = null): AiAlert
    {
        if (! in_array($status, ['dismissed', 'resolved'], true) || $alert->status !== 'open') {
            throw new DomainException('Only an open alert can be dismissed or resolved.');
        }
        $alert->forceFill(['status' => $status, 'handled_by_name' => $actor->name, 'handled_note' => $note ? mb_substr($note, 0, 255) : null, 'handled_at' => now()]);

        return $this->recorder->transaction(function () use ($actor, $alert, $status) {
            $alert->save();
            $this->recorder->audit->record($actor, "ai_alert.{$status}", $alert, ['status' => ['open', $status]], $alert->handled_note, $alert->branch_id);

            return $alert;
        });
    }
}
