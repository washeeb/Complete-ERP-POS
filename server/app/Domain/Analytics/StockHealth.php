<?php

namespace App\Domain\Analytics;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stock intelligence: dead stock, overstock, expiry risk and transfers between branches, from stock on hand and the last
 * 12 weeks of sales. Values at average cost.
 */
final class StockHealth
{
    public const DEAD_DAYS = 90;

    public const OVERSTOCK_DAYS = 120;

    /**
     * @param  list<string>  $warehouseIds
     * @return array{dead: list<array>, overstock: list<array>, expiry: list<array>, transfers: list<array>}
     */
    public function report(string $tenantId, array $warehouseIds, ?CarbonImmutable $today = null): array
    {
        $tz = Tenant::find($tenantId)?->timezone ?: 'UTC';
        $today ??= CarbonImmutable::now($tz)->startOfDay();
        $rate = $this->dailyRates($warehouseIds, $today, $tz); // [warehouse][product] => base units a day
        $names = DB::table('warehouses')->whereIn('id', $warehouseIds)->pluck('name', 'id');
        $levels = DB::table('stock_levels as l')->join('products as p', 'p.id', '=', 'l.product_id')
            ->whereIn('l.warehouse_id', $warehouseIds)->where('l.on_hand', '>', 0)->where('p.track_stock', true)
            ->get(['l.warehouse_id', 'l.product_id', 'l.on_hand', 'l.avg_cost_minor', 'p.sku', 'p.name']);
        $lastSale = DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')->whereIn('s.warehouse_id', $warehouseIds)->where('s.status', 'posted')
            ->groupBy('s.warehouse_id', 'l.product_id')->selectRaw('s.warehouse_id, l.product_id, MAX(s.occurred_at) AS at')->get()
            ->mapWithKeys(fn ($r) => ["{$r->warehouse_id}|{$r->product_id}" => $r->at]);

        $dead = [];
        $over = [];
        foreach ($levels as $l) {
            $qty = (float) $l->on_hand;
            $value = (int) round($qty * (float) $l->avg_cost_minor);
            $last = $lastSale["{$l->warehouse_id}|{$l->product_id}"] ?? null;
            $daily = $rate[$l->warehouse_id][$l->product_id] ?? 0.0;
            $row = ['warehouse_id' => $l->warehouse_id, 'warehouse' => $names[$l->warehouse_id], 'product_id' => $l->product_id, 'sku' => $l->sku, 'name' => $l->name,
                'on_hand' => $qty, 'value_minor' => $value];
            if (! $last || CarbonImmutable::parse($last)->lt($today->subDays(self::DEAD_DAYS))) {
                $dead[] = $row + ['last_sold' => $last ? CarbonImmutable::parse($last)->setTimezone($tz)->toDateString() : null];
            } elseif ($daily > 0 && $qty / $daily > self::OVERSTOCK_DAYS) {
                $excess = $qty - $daily * 60; // keep two months
                $over[] = $row + ['days_cover' => (int) round($qty / $daily), 'excess' => round($excess, 1), 'excess_value_minor' => (int) round($excess * (float) $l->avg_cost_minor)];
            }
        }
        usort($dead, fn ($a, $b) => $b['value_minor'] <=> $a['value_minor']);
        usort($over, fn ($a, $b) => $b['excess_value_minor'] <=> $a['excess_value_minor']);

        return ['dead' => $dead, 'overstock' => $over, 'expiry' => $this->expiry($warehouseIds, $rate, $names, $today), 'transfers' => $this->transfers($levels, $rate, $names)];
    }

    /** Base units sold a day over the last 28 days, per warehouse and product. */
    public function dailyRates(array $warehouseIds, CarbonImmutable $today, string $tz): array
    {
        $out = [];
        foreach ($warehouseIds as $w) {
            foreach (SalesHistory::daily([$w], $today->subDays(28), $today->subDay(), $tz) as $productId => $days) {
                $out[$w][$productId] = max(0.0, array_sum($days) / 28);
            }
        }

        return $out;
    }

    /** Batches that will not sell before they expire at the current rate (first-expiry-first-out). */
    private function expiry(array $warehouseIds, array $rate, $names, CarbonImmutable $today): array
    {
        $rows = DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')->join('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('stock_levels as s', fn ($j) => $j->on('s.warehouse_id', '=', 'l.warehouse_id')->on('s.product_id', '=', 'l.product_id'))
            ->whereIn('l.warehouse_id', $warehouseIds)->where('l.on_hand', '>', 0)->whereNotNull('b.expires_on')
            ->where('b.expires_on', '<=', $today->addDays(90)->toDateString())
            ->orderBy('l.warehouse_id')->orderBy('l.product_id')->orderBy('b.expires_on')
            ->get(['l.warehouse_id', 'l.product_id', 'p.sku', 'p.name', 'b.batch_no', 'b.expires_on', 'l.on_hand', 's.avg_cost_minor']);
        $out = [];
        $sold = []; // what earlier-expiring batches already use of the sales before each date
        foreach ($rows as $r) {
            $key = "{$r->warehouse_id}|{$r->product_id}";
            $daysLeft = max(0, $today->diffInDays(CarbonImmutable::parse($r->expires_on), false));
            $canSell = max(0.0, ($rate[$r->warehouse_id][$r->product_id] ?? 0) * $daysLeft - ($sold[$key] ?? 0));
            $atRisk = (float) $r->on_hand - $canSell;
            $sold[$key] = ($sold[$key] ?? 0) + min((float) $r->on_hand, $canSell);
            if ($atRisk > 0.0005) {
                $out[] = ['warehouse_id' => $r->warehouse_id, 'warehouse' => $names[$r->warehouse_id], 'product_id' => $r->product_id, 'sku' => $r->sku, 'name' => $r->name, 'batch_no' => $r->batch_no,
                    'expires_on' => $r->expires_on, 'days_left' => (int) $daysLeft, 'on_hand' => (float) $r->on_hand, 'at_risk' => round($atRisk, 1),
                    'value_minor' => (int) round($atRisk * (float) $r->avg_cost_minor),
                    'markdown_pct' => $daysLeft <= 7 ? 50 : ($daysLeft <= 30 ? 30 : 15)];
            }
        }
        usort($out, fn ($a, $b) => [$a['days_left'], -$a['value_minor']] <=> [$b['days_left'], -$b['value_minor']]);

        return $out;
    }

    /** Move stock from where it sits (over 60 days of cover) to where it is about to run out (under a week). */
    private function transfers($levels, array $rate, $names): array
    {
        $by = [];
        foreach ($levels as $l) {
            $by[$l->product_id][$l->warehouse_id] = $l;
        }
        $out = [];
        foreach ($rate as $to => $products) {
            foreach ($products as $productId => $daily) {
                $have = isset($by[$productId][$to]) ? (float) $by[$productId][$to]->on_hand : 0.0;
                if ($daily <= 0 || $have / $daily >= 7) {
                    continue;
                }
                $need = $daily * 21 - $have; // three weeks
                foreach ($by[$productId] ?? [] as $from => $l) {
                    $fromDaily = $rate[$from][$productId] ?? 0;
                    $spare = (float) $l->on_hand - $fromDaily * 60;
                    if ($from === $to || $spare <= 0 || $need <= 0) {
                        continue;
                    }
                    $qty = floor(min($spare, $need));
                    if ($qty >= 1) {
                        $out[] = ['product_id' => $productId, 'sku' => $l->sku, 'name' => $l->name, 'from' => $names[$from], 'to' => $names[$to],
                            'qty' => $qty, 'to_days_cover' => round($have / $daily, 1)];
                        $need -= $qty;
                    }
                }
            }
        }

        return $out;
    }
}
