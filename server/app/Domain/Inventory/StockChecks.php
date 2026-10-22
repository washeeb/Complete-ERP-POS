<?php

namespace App\Domain\Inventory;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Smart checks for the people who look after stock (Stock Controller release 4): plain rules on the stock ledger that
 * point at what deserves a second look — never a verdict. Shown on Stock → My day.
 *  - Larger than usual: a loss (adjustment out or count shortage) in the last 30 days worth at least three times the
 *    store's usual loss line (median of the 180 days before) and at least GHS 100.
 *  - Again and again: a product written off three times or more in the last 30 days.
 *  - Possible duplicates: active products with the same name (spaces and capitals ignored) in the same industry.
 */
final class StockChecks
{
    public const MIN_VALUE_MINOR = 10_000;

    /** @return Collection<int, object{when: string, sku: string, name: string, value: int, usual: int, by: string, reason: ?string, source_type: ?string, source_id: ?string}> */
    public function largerThanUsual(string $warehouseId, ?Carbon $today = null): Collection
    {
        $today ??= now();
        $lossValue = 'ABS(ROUND(m.qty_entered * COALESCE(m.unit_cost_minor, 0)))';
        $usual = (int) DB::table('stock_movements as m')->where('m.warehouse_id', $warehouseId)->whereIn('m.type', ['adjustment', 'count'])->where('m.qty_base', '<', 0)
            ->whereBetween('m.occurred_at', [$today->copy()->subDays(210), $today->copy()->subDays(30)])
            ->selectRaw("percentile_cont(0.5) WITHIN GROUP (ORDER BY {$lossValue}) AS median, COUNT(*) AS n")->first()?->median;
        $threshold = max(self::MIN_VALUE_MINOR, 3 * $usual);

        return DB::table('stock_movements as m')->join('products as p', 'p.id', '=', 'm.product_id')
            ->where('m.warehouse_id', $warehouseId)->whereIn('m.type', ['adjustment', 'count'])->where('m.qty_base', '<', 0)
            ->where('m.occurred_at', '>=', $today->copy()->subDays(30))->whereRaw("{$lossValue} >= ?", [$threshold])
            ->orderByDesc('m.occurred_at')->limit(20)
            ->get(['m.occurred_at', 'p.sku', 'p.name', DB::raw("{$lossValue}::bigint AS value"), 'm.actor_name', 'm.reason', 'm.source_type', 'm.source_id'])
            ->map(fn ($r) => (object) ['when' => Carbon::parse($r->occurred_at)->toDateString(), 'sku' => $r->sku, 'name' => $r->name, 'value' => (int) $r->value,
                'usual' => $usual, 'by' => $r->actor_name, 'reason' => $r->reason, 'source_type' => $r->source_type, 'source_id' => $r->source_id]);
    }

    /** @return Collection<int, object{sku: string, name: string, times: int, value: int}> */
    public function againAndAgain(string $warehouseId, ?Carbon $today = null): Collection
    {
        return DB::table('stock_movements as m')->join('products as p', 'p.id', '=', 'm.product_id')
            ->where('m.warehouse_id', $warehouseId)->whereIn('m.type', ['adjustment', 'count'])->where('m.qty_base', '<', 0)
            ->where('m.occurred_at', '>=', ($today ?? now())->copy()->subDays(30))
            ->groupBy('p.sku', 'p.name')->havingRaw('COUNT(*) >= 3')
            ->selectRaw('p.sku, p.name, COUNT(*) AS times, -SUM(ROUND(m.qty_entered * COALESCE(m.unit_cost_minor, 0)))::bigint AS value')
            ->orderByDesc('times')->limit(20)->get()
            ->map(fn ($r) => (object) ['sku' => $r->sku, 'name' => $r->name, 'times' => (int) $r->times, 'value' => (int) $r->value]);
    }

    /** @return Collection<int, object{name: string, skus: string, count: int}> */
    public function possibleDuplicates(string $tenantId): Collection
    {
        return DB::table('products')->where('tenant_id', $tenantId)->where('is_active', true)
            ->groupByRaw("lower(regexp_replace(trim(name), '\\s+', ' ', 'g')), industry_id")->havingRaw('COUNT(*) > 1')
            ->selectRaw("MIN(name) AS name, string_agg(sku, ', ' ORDER BY sku) AS skus, COUNT(*) AS count")
            ->orderBy('name')->limit(20)->get()
            ->map(fn ($r) => (object) ['name' => $r->name, 'skus' => $r->skus, 'count' => (int) $r->count]);
    }
}
