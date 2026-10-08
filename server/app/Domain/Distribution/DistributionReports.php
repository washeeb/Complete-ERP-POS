<?php

namespace App\Domain\Distribution;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Rep performance against targets, route coverage and the empties customers hold. Read-only queries. */
final class DistributionReports
{
    /**
     * Per rep for a period (local dates): sales before tax on van tills (returns netted), visits, strike rate (visits that
     * sold ÷ visits), orders taken, collections, and the monthly target(s) in the period with % achieved.
     */
    public static function reps(string $from, string $to, string $tz, ?array $branchIds = null): array
    {
        $a = Carbon::parse($from, $tz)->startOfDay()->utc();
        $b = Carbon::parse($to, $tz)->addDay()->startOfDay()->utc();
        $vanTills = DB::table('tills as t')->join('warehouses as w', 'w.id', '=', 't.warehouse_id')->where('w.is_van', true)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('t.branch_id', $branchIds))->pluck('t.id');
        $sales = DB::table('sales')->whereIn('till_id', $vanTills)->where('status', 'posted')->where('occurred_at', '>=', $a)->where('occurred_at', '<', $b)
            ->groupBy('cashier_id')->selectRaw('cashier_id AS user_id, SUM(net_minor) AS net, COUNT(*) FILTER (WHERE kind = \'sale\') AS receipts')->get()->keyBy('user_id');
        $visits = DB::table('visits')->where('occurred_at', '>=', $a)->where('occurred_at', '<', $b)->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->groupBy('user_id')->selectRaw("user_id, COUNT(*) AS visits, COUNT(*) FILTER (WHERE outcome = 'sold') AS sold, COUNT(*) FILTER (WHERE outcome = 'order') AS orders")->get()->keyBy('user_id');
        $collections = DB::table('customer_payments as p')->join('shifts as s', 's.id', '=', 'p.shift_id')->whereIn('s.till_id', $vanTills)
            ->where('p.received_on', '>=', Carbon::parse($from)->toDateString())->where('p.received_on', '<=', Carbon::parse($to)->toDateString())
            ->groupBy('s.opened_by')->selectRaw('s.opened_by AS user_id, SUM(p.amount_minor) AS amount')->get()->keyBy('user_id');
        $months = [];
        for ($m = Carbon::parse($from)->startOfMonth(); $m->lte(Carbon::parse($to)); $m->addMonth()) {
            $months[] = [$m->year, $m->month];
        }
        $targets = DB::table('rep_targets')->where(fn ($q) => collect($months)->each(fn ($ym) => $q->orWhere(fn ($w) => $w->where('year', $ym[0])->where('month', $ym[1]))))
            ->groupBy('user_id')->selectRaw('user_id, SUM(target_minor) AS target, SUM(visit_target) AS visit_target')->get()->keyBy('user_id');
        $ids = collect([$sales->keys(), $visits->keys(), $targets->keys(), $collections->keys()])->flatten()->unique()->filter()->values();
        $names = DB::table('users')->whereIn('id', $ids)->pluck('name', 'id');

        return $ids->map(function ($id) use ($sales, $visits, $collections, $targets, $names) {
            $net = (int) ($sales[$id]->net ?? 0);
            $target = (int) ($targets[$id]->target ?? 0);
            $v = (int) ($visits[$id]->visits ?? 0);

            return ['user_id' => $id, 'name' => $names[$id] ?? '—', 'sales_minor' => $net, 'receipts' => (int) ($sales[$id]->receipts ?? 0),
                'target_minor' => $target, 'achieved_bp' => $target > 0 ? intdiv($net * 10000, $target) : null,
                'visits' => $v, 'visits_sold' => (int) ($visits[$id]->sold ?? 0), 'orders' => (int) ($visits[$id]->orders ?? 0),
                'strike_bp' => $v > 0 ? intdiv((int) ($visits[$id]->sold ?? 0) * 10000, $v) : null, 'visit_target' => $targets[$id]->visit_target ?? null,
                'collections_minor' => (int) ($collections[$id]->amount ?? 0)];
        })->sortByDesc('sales_minor')->values()->all();
    }

    /** Customers on a route who were not visited on a given day it runs. */
    public static function missedVisits(string $date, string $tz, ?array $branchIds = null): array
    {
        $day = Carbon::parse($date, $tz);
        $a = $day->copy()->startOfDay()->utc();
        $b = $day->copy()->addDay()->startOfDay()->utc();

        return DB::table('route_stops as s')->join('routes as r', 'r.id', '=', 's.route_id')->join('customers as c', 'c.id', '=', 's.customer_id')
            ->where('r.is_active', true)->whereRaw('r.days @> ?::jsonb', [json_encode([$day->isoWeekday()])])
            ->when($branchIds !== null, fn ($q) => $q->whereIn('r.branch_id', $branchIds))
            ->whereNotExists(fn ($q) => $q->from('visits as v')->whereColumn('v.customer_id', 's.customer_id')->where('v.occurred_at', '>=', $a)->where('v.occurred_at', '<', $b))
            ->orderBy('r.code')->orderBy('s.sequence')->get(['r.code as route', 's.sequence', 'c.code', 'c.name', 'c.phone'])->all();
    }

    /** Returnable containers each customer holds: sold to them minus returned (positive = they hold ours). */
    public static function empties(?array $branchIds = null): array
    {
        return DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')->join('products as p', 'p.id', '=', 'l.product_id')
            ->join('customers as c', 'c.id', '=', 's.customer_id')->where('p.is_container', true)->where('s.status', 'posted')
            ->when($branchIds !== null, fn ($q) => $q->whereIn('s.branch_id', $branchIds))
            ->groupBy('c.id', 'c.code', 'c.name', 'p.sku', 'p.name')->havingRaw('SUM(l.qty) <> 0')->orderBy('c.name')
            ->selectRaw('c.code, c.name AS customer, p.sku, p.name AS container, SUM(l.qty) AS held, SUM(l.line_total_minor) AS deposit_minor')->get()->all();
    }
}
