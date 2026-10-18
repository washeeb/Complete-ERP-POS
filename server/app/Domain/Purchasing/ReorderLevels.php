<?php

namespace App\Domain\Purchasing;

use App\Models\Warehouse;
use App\Support\Qty;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * The minimum and maximum stock of every product in a store. A product's own figure for that store (typed in, or a
 * smart level accepted or set automatically) wins; otherwise the company default applies: N of the unit it is bought
 * in (Settings → Stock, 5 by default), topped up to twice that. With "leave out slow movers" on, products that have not
 * sold in the store for 180 days (and are older than that) get no default, so dead stock is not reordered.
 */
final class ReorderLevels
{
    public const SLOW_MOVER_DAYS = 180;

    public const SOURCES = ['own' => 'Own figure', 'smart' => 'Smart level (from sales)', 'default' => 'Company default'];

    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  list<string>|null  $productIds  null = every active stock-tracked product sold in the store's industry
     * @return array<string, array{min: int, max: int, source: string}> product id => levels in base units (thousandths); products without a level are left out
     */
    public function forWarehouse(Warehouse $warehouse, ?array $productIds = null): array
    {
        $industry = DB::table('branches')->where('id', $warehouse->branch_id)->value('industry_id');
        $products = DB::table('products')->where('tenant_id', $warehouse->tenant_id)->where('is_active', true)->where('track_stock', true)
            ->when($industry, fn ($q) => $q->where(fn ($w) => $w->whereNull('industry_id')->orWhere('industry_id', $industry)))
            ->when($productIds !== null, fn ($q) => $q->whereIn('id', $productIds))
            ->pluck('created_at', 'id');
        if ($products->isEmpty()) {
            return [];
        }
        $rules = DB::table('reorder_rules')->where('warehouse_id', $warehouse->id)->where('source', '<>', 'default')
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds))->get(['product_id', 'min_qty', 'max_qty', 'source'])->keyBy('product_id');
        $default = (int) $this->settings->get('stock.default_min_qty', $warehouse->tenant_id);
        $factors = $default > 0 ? self::buyingFactors($products->keys()->all()) : [];
        $slow = $default > 0 && $this->settings->get('stock.default_exclude_slow_movers', $warehouse->tenant_id)
            ? $this->slowMovers($warehouse, $products->all()) : [];

        $out = [];
        foreach ($products as $id => $createdAt) {
            if ($r = $rules[$id] ?? null) {
                $out[$id] = ['min' => Qty::toMilli((string) $r->min_qty), 'max' => Qty::toMilli((string) $r->max_qty), 'source' => $r->source];
            } elseif ($default > 0 && ! isset($slow[$id])) {
                $min = (int) round($default * 1000 * ($factors[$id] ?? 1_000_000) / 1_000_000);
                $out[$id] = ['min' => $min, 'max' => 2 * $min, 'source' => 'default'];
            }
        }

        return $out;
    }

    /**
     * The unit each product is bought in, as micro-factor of its base unit: the preferred (else cheapest) active
     * supplier's unit, else its biggest purchasable unit, else the base unit.
     *
     * @param  list<string>  $productIds
     * @return array<string, int>
     */
    public static function buyingFactors(array $productIds): array
    {
        $out = [];
        foreach (array_chunk($productIds, 1000) as $chunk) {
            foreach (DB::table('product_uoms')->whereIn('product_id', $chunk)->where('is_purchasable', true)->orderBy('factor')->get(['product_id', 'factor']) as $u) {
                $out[$u->product_id] = Qty::factorMicro((string) $u->factor); // biggest wins (ordered by factor)
            }
            $supplied = DB::table('supplier_products as sp')->join('suppliers as s', 's.id', '=', 'sp.supplier_id')->join('product_uoms as u', 'u.id', '=', 'sp.product_uom_id')
                ->whereIn('sp.product_id', $chunk)->where('s.is_active', true)
                ->orderBy('sp.product_id')->orderByDesc('sp.is_preferred')->orderByRaw('sp.last_cost_minor IS NULL')->orderBy('sp.last_cost_minor')
                ->get(['sp.product_id', 'u.factor'])->unique('product_id');
            foreach ($supplied as $s) {
                $out[$s->product_id] = Qty::factorMicro((string) $s->factor);
            }
        }

        return $out;
    }

    /** The buying unit's code and micro-factor for one product (for screens and imports). */
    public static function buyingUnit(string $productId): array
    {
        $factor = self::buyingFactors([$productId])[$productId] ?? 1_000_000;
        $code = DB::table('product_uoms as pu')->join('uoms as u', 'u.id', '=', 'pu.uom_id')->where('pu.product_id', $productId)
            ->orderByRaw('ABS(pu.factor * 1000000 - ?)', [$factor])->orderByDesc('pu.is_base')->value('u.code');

        return ['code' => (string) $code, 'factor' => $factor];
    }

    /**
     * Products at or below their minimum in the store (stock on hand only).
     *
     * @return array<string, array{on_hand: int, min: int, max: int, source: string}>
     */
    public function low(Warehouse $warehouse): array
    {
        $levels = $this->forWarehouse($warehouse);
        $onHand = DB::table('stock_levels')->where('warehouse_id', $warehouse->id)->pluck('on_hand', 'product_id');
        $out = [];
        foreach ($levels as $id => $l) {
            $have = Qty::toMilli((string) ($onHand[$id] ?? '0'));
            if ($l['min'] > 0 && $have <= $l['min']) {
                $out[$id] = ['on_hand' => $have] + $l;
            }
        }

        return $out;
    }

    /** @param array<string, string> $products id => created_at */
    private function slowMovers(Warehouse $warehouse, array $products): array
    {
        $since = now()->subDays(self::SLOW_MOVER_DAYS);
        $sold = DB::table('stock_movements')->where('warehouse_id', $warehouse->id)->where('type', 'sale')->where('occurred_at', '>=', $since)
            ->distinct()->pluck('product_id')->flip();
        $out = [];
        foreach ($products as $id => $createdAt) {
            if (! isset($sold[$id]) && $createdAt !== null && $createdAt < $since->toDateTimeString()) {
                $out[$id] = true;
            }
        }

        return $out;
    }
}
