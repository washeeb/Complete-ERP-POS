<?php

namespace App\Domain\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Till sales (returns netted) in base units per product and local day — the raw material of the forecasts. */
final class SalesHistory
{
    /**
     * @param  list<string>  $warehouseIds
     * @param  list<string>|null  $productIds
     * @return array<string, array<string, float>> product id => [Y-m-d => base qty]
     */
    public static function daily(array $warehouseIds, CarbonImmutable $from, CarbonImmutable $to, string $tz, ?array $productIds = null): array
    {
        $day = "(s.occurred_at AT TIME ZONE 'UTC' AT TIME ZONE ".DB::getPdo()->quote($tz).')::date';
        $rows = DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')
            ->whereIn('s.warehouse_id', $warehouseIds)->where('s.status', 'posted')
            // A plain range a day wider each side lets the index narrow the sales; the local-day test below is exact.
            ->where('s.occurred_at', '>=', $from->subDay()->toDateString())->where('s.occurred_at', '<', $to->addDays(2)->toDateString())
            ->whereRaw("{$day} BETWEEN ? AND ?", [$from->toDateString(), $to->toDateString()])
            ->when($productIds !== null, fn ($q) => $q->whereIn('l.product_id', $productIds))
            ->groupBy('l.product_id', DB::raw($day))
            ->selectRaw("l.product_id, {$day}::text AS d, SUM(l.qty * l.factor) AS q")->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->product_id][$r->d] = (float) $r->q;
        }

        return $out;
    }
}
