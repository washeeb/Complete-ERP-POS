<?php

namespace App\Domain\Analytics;

use App\Models\Tenant;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Demand forecast per product and warehouse from the last 12 weeks of sales — classical, runs on the node, no AI needed.
 *
 * Daily demand = recent level (last four weeks, the most recent weighted most) × weekday pattern × payday effect ×
 * public-holiday effect. Paydays in Ghana cluster at the end and start of the month (25th–3rd); the effect is measured
 * from the shop's own history and kept within sensible bounds. Reorder suggestions cover the supplier's lead time plus a
 * week, with safety stock from how much daily sales vary.
 */
final class DemandForecaster
{
    public const HISTORY_DAYS = 84;

    /** @var array<string, list<array{0: string, 1: int, 2: bool, 3: bool, 4: bool}>> calendars already worked out, by start and length */
    private static array $calendars = [];

    /**
     * @param  list<string>|null  $productIds  null = every product sold at the warehouse in the history window
     * @return list<array<string, mixed>> one row per product, sorted by days of cover (shortest first)
     */
    public function forWarehouse(string $warehouseId, ?array $productIds = null, int $horizon = 28, ?CarbonImmutable $today = null): array
    {
        $warehouse = Warehouse::findOrFail($warehouseId);
        $tz = Tenant::find($warehouse->tenant_id)?->timezone ?: 'UTC';
        $today ??= CarbonImmutable::now($tz)->startOfDay();
        $from = $today->subDays(self::HISTORY_DAYS);
        $series = SalesHistory::daily([$warehouseId], $from, $today->subDay(), $tz, $productIds);
        if ($productIds !== null) {
            foreach ($productIds as $id) {
                $series[$id] ??= [];
            }
        }
        $products = DB::table('products')->whereIn('id', array_keys($series))->get(['id', 'sku', 'name'])->keyBy('id');
        $levels = DB::table('stock_levels')->where('warehouse_id', $warehouseId)->whereIn('product_id', array_keys($series))->get(['product_id', 'on_hand', 'avg_cost_minor'])->keyBy('product_id');
        $onOrder = DB::table('purchase_order_lines as l')->join('purchase_orders as o', 'o.id', '=', 'l.purchase_order_id')
            ->where('o.warehouse_id', $warehouseId)->whereIn('o.status', ['approved', 'partially_received'])->whereIn('l.product_id', array_keys($series))
            ->groupBy('l.product_id')->selectRaw('l.product_id, SUM(GREATEST(l.qty - l.received_qty, 0) * l.factor) AS q')->pluck('q', 'product_id');
        $lead = DB::table('supplier_products as sp')->join('suppliers as s', 's.id', '=', 'sp.supplier_id')->whereIn('sp.product_id', array_keys($series))
            ->orderByDesc('sp.is_preferred')->get(['sp.product_id', 's.lead_time_days'])->unique('product_id')->pluck('lead_time_days', 'product_id');

        $out = [];
        foreach ($series as $productId => $days) {
            if (! isset($products[$productId])) {
                continue;
            }
            $f = self::model($days, $from, self::HISTORY_DAYS);
            $daily = array_map(fn (array $day) => self::predictOn($f, $day), self::calendar($today, $horizon));
            $onHand = (float) ($levels[$productId]->on_hand ?? 0);
            $incoming = (float) ($onOrder[$productId] ?? 0);
            $leadDays = (int) ($lead[$productId] ?? 7) ?: 7;
            $perDay = $horizon ? array_sum($daily) / $horizon : 0;
            $cover = $perDay > 0 ? $onHand / $perDay : null;
            $safety = 1.65 * $f['sd'] * sqrt($leadDays); // about 95 % of days covered
            $target = array_sum(array_slice($daily, 0, min($horizon, $leadDays + 7))) + $safety;
            $min = $perDay * $leadDays + $safety;
            $out[] = ['product_id' => $productId, 'sku' => $products[$productId]->sku, 'name' => $products[$productId]->name,
                'avg_daily' => round($f['recent'], 3), 'forecast_7' => round(array_sum(array_slice($daily, 0, 7)), 1), 'forecast_28' => round(array_sum($daily), 1),
                'trend_pct' => $f['trend'] === null ? null : (int) round(($f['trend'] - 1) * 100),
                'on_hand' => $onHand, 'on_order' => $incoming, 'lead_days' => $leadDays, 'days_cover' => $cover === null ? null : round($cover, 1),
                'stockout_on' => $cover === null || $cover >= $horizon ? null : $today->addDays((int) floor($cover))->toDateString(),
                'suggested_order' => max(0, (float) ceil($target - $onHand - $incoming)),
                'suggested_min' => round($min, 1), 'suggested_max' => round($min + $perDay * 14, 1),
                'avg_cost_minor' => isset($levels[$productId]->avg_cost_minor) ? (float) $levels[$productId]->avg_cost_minor : null];
        }
        usort($out, fn ($a, $b) => [$a['days_cover'] ?? INF, $a['name']] <=> [$b['days_cover'] ?? INF, $b['name']]);

        return $out;
    }

    /**
     * Fit the simple model on a product's daily history ([Y-m-d => qty]).
     *
     * @return array{recent: float, weekday: array<int, float>, payday: float, holiday: float, eve: float, sd: float, trend: ?float}
     */
    public static function model(array $days, CarbonImmutable $from, int $n): array
    {
        $calendar = self::calendar($from, $n);
        $q = [];
        foreach ($calendar as [$date]) {
            $q[] = (float) ($days[$date] ?? 0);
        }
        $mean = array_sum($q) / max(1, $n);
        // Recent level: the last four weeks, weights 4-3-2-1 from the most recent.
        $weeks = [];
        for ($w = 0; $w < 4; $w++) {
            $slice = array_slice($q, $n - 7 * ($w + 1), 7);
            $weeks[] = count($slice) ? array_sum($slice) / count($slice) : 0;
        }
        $recent = ($weeks[0] * 4 + $weeks[1] * 3 + $weeks[2] * 2 + $weeks[3]) / 10;
        $last28 = array_sum(array_slice($q, -28));
        $prev28 = array_sum(array_slice($q, -56, 28));
        $trend = $prev28 > 0 ? max(0.25, min(4.0, $last28 / $prev28)) : null;

        $byWd = array_fill(1, 7, [0.0, 0]);
        $pay = [0.0, 0];
        $other = [0.0, 0];
        $hol = [0.0, 0];
        $eve = [0.0, 0];
        foreach ($q as $i => $v) {
            [, $wd, $isPayday, $isHoliday, $isEve] = $calendar[$i];
            $byWd[$wd][0] += $v;
            $byWd[$wd][1]++;
            if ($isPayday) {
                $pay[0] += $v;
                $pay[1]++;
            } else {
                $other[0] += $v;
                $other[1]++;
            }
            if ($isHoliday) {
                $hol[0] += $v;
                $hol[1]++;
            } elseif ($isEve) {
                $eve[0] += $v;
                $eve[1]++;
            }
        }
        $weekday = [];
        foreach ($byWd as $wd => [$sum, $count]) {
            // Shrunk towards 1 so a few odd days do not swing the forecast.
            $weekday[$wd] = $mean > 0 ? max(0.2, min(3.0, (($count ? $sum / $count : $mean) * $count + $mean * 2) / (($count + 2) * $mean))) : 1.0;
        }
        $ratio = fn (array $a, float $fallback, float $lo, float $hi) => $a[1] >= 2 && $mean > 0 ? max($lo, min($hi, ($a[0] / $a[1]) / $mean)) : $fallback;
        $payday = $other[1] && $other[0] > 0 && $pay[1] ? max(0.7, min(1.6, ($pay[0] / $pay[1]) / ($other[0] / $other[1]))) : 1.0;
        $sd = $n > 1 ? sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $q)) / ($n - 1)) : 0.0;

        return ['recent' => $recent, 'weekday' => $weekday, 'payday' => $payday, 'holiday' => $ratio($hol, 0.8, 0.2, 1.5),
            'eve' => $ratio($eve, 1.2, 0.8, 2.0), 'sd' => $sd, 'trend' => $trend];
    }

    public static function predict(array $f, CarbonImmutable $day): float
    {
        return self::predictOn($f, self::calendar($day, 1)[0]);
    }

    /** @param  array{0: string, 1: int, 2: bool, 3: bool, 4: bool}  $day  one day of calendar() */
    private static function predictOn(array $f, array $day): float
    {
        [, $wd, $isPayday, $isHoliday, $isEve] = $day;
        $v = $f['recent'] * $f['weekday'][$wd];
        if ($isPayday) {
            $v *= $f['payday'];
        }
        if ($isHoliday) {
            $v *= $f['holiday'];
        } elseif ($isEve) {
            $v *= $f['eve'];
        }

        return max(0.0, $v);
    }

    /**
     * The days from $from: date, ISO weekday, payday, public holiday, eve of a public holiday. Worked out once and shared
     * by every product, since date arithmetic per product per day made the forecast take seconds for a full shop.
     *
     * @return list<array{0: string, 1: int, 2: bool, 3: bool, 4: bool}>
     */
    private static function calendar(CarbonImmutable $from, int $n): array
    {
        $key = $from->toDateString().'/'.$n;
        if (! isset(self::$calendars[$key])) {
            if (count(self::$calendars) > 50) { // long-running workers move on from day to day
                self::$calendars = [];
            }
            $days = [];
            for ($i = 0; $i < $n; $i++) {
                $d = $from->addDays($i);
                $holiday = GhanaHolidays::is($d);
                $days[] = [$d->toDateString(), $d->dayOfWeekIso, self::isPayday($d), $holiday, ! $holiday && GhanaHolidays::is($d->addDay())];
            }
            self::$calendars[$key] = $days;
        }

        return self::$calendars[$key];
    }

    public static function isPayday(CarbonImmutable $d): bool
    {
        return $d->day >= 25 || $d->day <= 3;
    }
}
