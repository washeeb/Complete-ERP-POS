<?php

namespace App\Domain\Fashion;

use App\Models\Style;
use App\Support\Qty;
use Illuminate\Support\Facades\DB;

/** Style-based reporting (Fashion): sell-through by style and season, and the size × colour matrix of stock and sales. */
final class FashionReports
{
    /**
     * Per style over a period: units sold (net of returns), on hand now, sell-through = sold ÷ (sold + on hand).
     *
     * @return list<object{style_id: string, code: string, name: string, season: ?string, collection: ?string, sold: string, on_hand: string, sell_through_bp: int, revenue_minor: int}>
     */
    public static function styles(string $from, string $to, ?string $season = null, ?array $branchIds = null): array
    {
        $sold = DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')->join('products as p', 'p.id', '=', 'l.product_id')
            ->whereNotNull('p.style_id')->where('s.status', 'posted')->whereBetween('s.occurred_at', [$from.' 00:00:00', $to.' 23:59:59.999999'])
            ->when($branchIds !== null, fn ($q) => $q->whereIn('s.branch_id', $branchIds))
            ->groupBy('p.style_id')->selectRaw('p.style_id, SUM(l.qty * l.factor) AS qty, SUM(l.net_minor) AS revenue')->get()->keyBy('style_id');
        $stock = DB::table('stock_levels as sl')->join('products as p', 'p.id', '=', 'sl.product_id')->join('warehouses as w', 'w.id', '=', 'sl.warehouse_id')
            ->whereNotNull('p.style_id')->when($branchIds !== null, fn ($q) => $q->whereIn('w.branch_id', $branchIds))
            ->groupBy('p.style_id')->selectRaw('p.style_id, SUM(sl.on_hand) AS qty')->pluck('qty', 'style_id');
        $out = [];
        foreach (Style::when($season, fn ($q) => $q->where('season', $season))->orderBy('code')->get() as $st) {
            $s = Qty::toMilli(number_format((float) ($sold[$st->id]->qty ?? 0), 3, '.', ''));
            $h = Qty::toMilli(number_format((float) ($stock[$st->id] ?? 0), 3, '.', ''));
            $out[] = (object) ['style_id' => $st->id, 'code' => $st->code, 'name' => $st->name, 'season' => $st->season, 'collection' => $st->collection,
                'sold' => Qty::format($s), 'on_hand' => Qty::format($h), 'sell_through_bp' => $s + $h > 0 ? intdiv($s * 10000, max(1, $s + max(0, $h))) : 0,
                'revenue_minor' => (int) ($sold[$st->id]->revenue ?? 0)];
        }

        return $out;
    }

    /**
     * The size × colour grid of one style: on hand (all warehouses, or one) and sold over a period.
     *
     * @return array{sizes: list<string>, colours: list<string>, cells: array<string, array<string, array{product_id: string, sku: string, on_hand: string, sold: string}>>}
     */
    public static function matrix(Style $style, string $from, string $to, ?string $warehouseId = null): array
    {
        $variants = DB::table('products')->where('style_id', $style->id)->get(['id', 'sku', 'size', 'colour']);
        $ids = $variants->pluck('id');
        $onHand = DB::table('stock_levels')->whereIn('product_id', $ids)->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->groupBy('product_id')->selectRaw('product_id, SUM(on_hand) AS q')->pluck('q', 'product_id');
        $sold = DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')->whereIn('l.product_id', $ids)->where('s.status', 'posted')
            ->whereBetween('s.occurred_at', [$from.' 00:00:00', $to.' 23:59:59.999999'])->groupBy('l.product_id')->selectRaw('l.product_id, SUM(l.qty * l.factor) AS q')->pluck('q', 'product_id');
        $cells = [];
        foreach ($variants as $v) {
            $cells[$v->colour][$v->size] = ['product_id' => $v->id, 'sku' => $v->sku, 'on_hand' => Qty::format(Qty::toMilli(number_format((float) ($onHand[$v->id] ?? 0), 3, '.', ''))),
                'sold' => Qty::format(Qty::toMilli(number_format((float) ($sold[$v->id] ?? 0), 3, '.', '')))];
        }

        return ['sizes' => $style->sizes, 'colours' => $style->colours, 'cells' => $cells];
    }
}
