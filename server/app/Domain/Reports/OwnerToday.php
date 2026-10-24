<?php

namespace App\Domain\Reports;

use App\Domain\Pos\DayCloseService;
use App\Domain\Support\SystemHealth;
use App\Models\Branch;
use App\Models\DayClose;
use App\Models\Tenant;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The owner's phone view (owner request 2026-10-24): the business today in one screen that reads well on a phone —
 * sales against the same day last week, by branch; cash, mobile money and card; tills open now; cash over / short;
 * who closed each branch's day; and what needs attention (server health, approvals, expiring stock, refused till work).
 */
final class OwnerToday
{
    public function __construct(private readonly SystemHealth $health) {}

    /** @param list<string>|null $branchIds null = every branch */
    public function build(Tenant $tenant, ?array $branchIds, ?Carbon $day = null): array
    {
        $tz = $tenant->timezone ?: 'UTC';
        $day = ($day ?? now())->copy()->setTimezone($tz);
        [$from, $to] = [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc()];
        $m = fn (int $v) => Money::display($v, $tenant->currency_code);
        $scope = fn ($q, string $col = 'branch_id') => $q->where('tenant_id', $tenant->id)->when($branchIds !== null, fn ($w) => $w->whereIn($col, $branchIds));
        $sales = fn (Carbon $a, Carbon $b) => $scope(DB::table('sales'))->where('status', 'posted')->whereBetween('occurred_at', [$a, $b]);

        $total = (int) $sales($from, $to)->sum('total_minor');
        $lastWeek = (int) $sales($from->copy()->subWeek(), $to->copy()->subWeek())->sum('total_minor');
        $byBranch = $sales($from, $to)->groupBy('branch_id')->selectRaw('branch_id, SUM(total_minor) AS t, COUNT(*) AS n')->get()->keyBy('branch_id');
        $branches = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('is_active', true)->when($branchIds !== null, fn ($q) => $q->whereIn('id', $branchIds))
            ->orderBy('code')->get(['id', 'name']);
        $closes = DayClose::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereDate('business_date', $day->toDateString())->get()->keyBy('branch_id');
        $pay = DB::table('sale_payments as p')->join('sales as s', 's.id', '=', 'p.sale_id')->where('s.tenant_id', $tenant->id)->where('s.status', 'posted')
            ->when($branchIds !== null, fn ($q) => $q->whereIn('s.branch_id', $branchIds))->whereBetween('s.occurred_at', [$from, $to])
            ->groupBy('p.method')->selectRaw('p.method, SUM(p.amount_minor) AS t')->pluck('t', 'method');
        $open = DB::table('shifts')->join('tills', 'tills.id', '=', 'shifts.till_id')->where('shifts.tenant_id', $tenant->id)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('shifts.branch_id', $branchIds))->where('shifts.status', 'open')
            ->get(['tills.name as till', 'shifts.opened_by_name as who', 'shifts.branch_id']);
        $closed = $scope(DB::table('shifts'))->where('status', 'closed')->whereBetween('closed_at', [$from, $to]);
        $overShort = (int) (clone $closed)->sum('over_short_minor');

        // What needs attention: each with where to deal with it.
        $healthFails = Cache::remember("owner-today-health:{$tenant->id}", 300, fn () => collect($this->health->run())->where('status', 'fail')->pluck('check')->values()->all());
        $approvals = $scope(DB::table('purchase_orders'))->where('status', 'pending_approval')->count() + $scope(DB::table('stock_adjustments'))->where('status', 'submitted')->count()
            + $scope(DB::table('stock_counts'))->where('status', 'submitted')->count() + $scope(DB::table('expense_claims'))->where('status', 'submitted')->count();
        $expiring = DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->where('b.tenant_id', $tenant->id)->when($branchIds !== null, fn ($q) => $q->whereIn('w.branch_id', $branchIds))->where('l.on_hand', '>', 0)
            ->where('b.expires_on', '<=', $day->copy()->addDays(30)->toDateString())->count();
        $refused = $scope(DB::table('review_items'))->where('status', 'open')->count();

        return [
            'date' => $day->format('l j M'),
            'sales' => $m($total), 'sales_minor' => $total, 'count' => (int) $byBranch->sum('n'),
            'vs_last_week' => $lastWeek ? (int) round(($total - $lastWeek) * 100 / $lastWeek) : null, 'last_week' => $m($lastWeek),
            'branches' => $branches->map(fn ($b) => ['name' => $b->name, 'sales' => $m((int) ($byBranch[$b->id]->t ?? 0)), 'count' => (int) ($byBranch[$b->id]->n ?? 0),
                'open' => $open->where('branch_id', $b->id)->map(fn ($s) => "{$s->till} ({$s->who})")->values()->all(),
                'closed' => isset($closes[$b->id]) ? 'Closed by '.$closes[$b->id]->closed_by_name.($closes[$b->id]->problems ? ' — '.$closes[$b->id]->note : '') : null,
                'deposit' => isset($closes[$b->id]) && $closes[$b->id]->banked_minor ? $m((int) $closes[$b->id]->banked_minor).' banked, '.DayCloseService::depositStatus($closes[$b->id])['text'] : null,
            ])->values()->all(),
            'payments' => ['Cash' => $m((int) ($pay['cash'] ?? 0)), 'Mobile money' => $m((int) ($pay['momo'] ?? 0)), 'Card' => $m((int) ($pay['card'] ?? 0))],
            'closes' => (clone $closed)->count(), 'over_short' => $overShort, 'over_short_text' => $overShort === 0 ? 'balanced' : ($overShort > 0 ? 'over ' : 'short ').$m(abs($overShort)),
            'attention' => array_values(array_filter([
                $healthFails ? ['tone' => 'danger', 'text' => 'Server needs attention: '.implode(', ', $healthFails), 'link' => 'health'] : null,
                $refused ? ['tone' => 'warning', 'text' => "{$refused} till item(s) refused, waiting in the review queue", 'link' => 'review'] : null,
                $approvals ? ['tone' => 'warning', 'text' => "{$approvals} document(s) waiting for approval", 'link' => null] : null,
                $expiring ? ['tone' => 'warning', 'text' => "{$expiring} batch(es) expired or expiring within 30 days", 'link' => 'expiry'] : null,
            ])),
        ];
    }
}
