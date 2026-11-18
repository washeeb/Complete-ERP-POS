<?php

namespace App\Domain\Office;

use App\Domain\Purchasing\ReorderLevels;
use App\Domain\Purchasing\ReorderService;
use App\Filament\Pages\ReorderSuggestions;
use App\Filament\Pages\SuggestedLevels;
use App\Filament\Resources\ReorderRules\ReorderRuleResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\ReorderRule;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Money;
use App\Support\Qty;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reorder levels, What to order and Suggested levels in the new back office (release 5 of the rewrite), with the current
 * screens' columns, filters and buttons, through ReorderService and SmartReorderLevels (ReorderController):
 *  - Reorder levels: the products with their own figure (or a smart level) per store, in the buying unit; add one for a
 *    store or every store, change it, or put it back on the company default.
 *  - What to order: what is at or below its minimum (counting what is on order and in transit), with the supplier, unit,
 *    quantity and cost to order; the buyer changes a line before ordering (kept on the page), asks other stores to send
 *    what they can spare, and makes one draft purchase order per supplier.
 *  - Suggested levels: minimum and maximum worked out from sales, to accept.
 */
final class ReorderScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public function __construct(private readonly User $user) {}

    public static function canRules(): bool
    {
        return ReorderRuleResource::canViewAny();
    }

    public static function canSetRules(): bool
    {
        return ReorderRuleResource::canCreate();
    }

    public static function canOrder(): bool
    {
        return ReorderSuggestions::canAccess();
    }

    public static function canSuggested(): bool
    {
        return SuggestedLevels::canAccess();
    }

    private static function levelWarehouses(): array
    {
        return Fields::warehouses(['warehouse.manage', 'stock.levels']);
    }

    // ---- Reorder levels --------------------------------------------------------------------------------------------

    public function rulesFiltered(array $p): Builder
    {
        $q = ReorderRuleResource::getEloquentQuery();
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->whereHas('product', fn (Builder $w) => $w->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%"));
        }
        if (filled($p['warehouse'] ?? null)) {
            $q->where('reorder_rules.warehouse_id', (string) $p['warehouse']);
        }
        if (in_array($p['set_by'] ?? '', ['own', 'smart'], true)) {
            $q->where('reorder_rules.source', $p['set_by']);
        }

        return $q;
    }

    public function rulesSorted(array $p): Builder
    {
        return $this->rulesFiltered($p)->join('products', 'products.id', '=', 'reorder_rules.product_id')->select('reorder_rules.*')
            ->orderBy('products.name', ($p['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc')->orderBy('reorder_rules.id');
    }

    /** @param  array{units: array, on_hand: array}  $page */
    public function ruleRow(ReorderRule $r, array $page): array
    {
        $u = $page['units'][$r->product_id] ?? null;
        $editable = Erp::can('warehouse.manage', $r->warehouse) || Erp::can('stock.levels', $r->warehouse);

        return [
            'id' => $r->id, 'product' => $r->product?->name, 'sku' => $r->product?->sku, 'store' => $r->warehouse?->code,
            'on_hand' => Fields::qty($page['on_hand'][$r->warehouse_id.'|'.$r->product_id] ?? null),
            'min' => ReorderRuleResource::inBuyingUnit($r->product_id, $r->min_qty, $u), 'max' => ReorderRuleResource::inBuyingUnit($r->product_id, $r->max_qty, $u),
            'source' => $r->source, 'source_label' => ReorderLevels::SOURCES[$r->source] ?? $r->source, 'editable' => $editable,
        ];
    }

    /** Buying units and stock on hand for the rows of a page, once (not per row), as the current list. */
    public function rulePage(iterable $rules): array
    {
        $rows = collect($rules);

        return ['units' => ReorderLevels::buyingUnits($rows->pluck('product_id')->unique()->values()->all()),
            'on_hand' => DB::table('stock_levels')->whereIn('product_id', $rows->pluck('product_id')->unique())->whereIn('warehouse_id', $rows->pluck('warehouse_id')->unique())
                ->get(['warehouse_id', 'product_id', 'on_hand'])->mapWithKeys(fn ($l) => [$l->warehouse_id.'|'.$l->product_id => $l->on_hand])->all()];
    }

    /** @param  array<string, mixed>  $p  q, warehouse, set_by, dir, page, per */
    public function rules(array $p): array
    {
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $rules = $this->rulesSorted($p)->forPage($page, $per)->get();
        $info = $this->rulePage($rules);
        $default = app(Settings::class)->get('stock.default_min_qty', $this->user->tenant_id);

        return [
            'total' => (clone $this->rulesFiltered($p))->count(), 'page' => $page, 'per' => $per, 'sort' => 'name', 'dir' => ($p['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
            'default_note' => "Every stock product without its own figure follows the company default (Settings → Stock: {$default} of its buying unit, ordered up to twice that). Figures here replace it in one store.",
            'rows' => $rules->map(fn (ReorderRule $r) => $this->ruleRow($r, $info))->all(),
        ];
    }

    public function rulesOptions(): array
    {
        return [
            'warehouses' => PurchaseOrdersScreen::choices(Warehouse::orderBy('code')->get()->mapWithKeys(fn ($w) => [$w->id => "{$w->code} · {$w->name}"])->all()),
            'set_by' => [['id' => 'own', 'label' => 'Own figure'], ['id' => 'smart', 'label' => 'Smart level']],
            'per' => self::PER_PAGE, 'can_set' => self::canSetRules(), 'can_order' => self::canOrder(), 'can_suggested' => self::canSuggested(),
            'stores' => self::canSetRules() ? [['id' => 'all', 'label' => 'Every store'], ...PurchaseOrdersScreen::choices(self::levelWarehouses())] : [],
        ];
    }

    /** Stock products for "Own figure for a product", and a product's units (its buying unit first). */
    public function products(string $q): array
    {
        if (mb_strlen($q) < 2) {
            return [];
        }

        return Product::where('track_stock', true)->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('sku', 'ilike', "%{$q}%"))->limit(30)->get()
            ->map(fn ($p) => ['id' => $p->id, 'label' => "{$p->sku} · {$p->name}"])->all();
    }

    public function productUnits(string $productId): array
    {
        $buying = ReorderLevels::buyingUnit($productId)['factor'];
        $units = ProductUom::with('uom')->where('product_id', $productId)->orderBy('factor')->get();
        $chosen = $units->sortBy(fn ($u) => abs(Qty::factorMicro((string) $u->factor) - $buying))->first()?->id;

        return ['units' => $units->map(fn ($u) => ['id' => $u->id, 'label' => $u->uom->code.($u->is_base ? '' : ' of '.Fields::qty((string) $u->factor))])->values()->all(),
            'buying' => $chosen];
    }

    /** A rule's figures for Edit: in the base unit, as the current Edit. */
    public function ruleForm(ReorderRule $r): array
    {
        return ['product' => $r->product?->name, 'store' => $r->warehouse?->code, 'product_id' => $r->product_id, 'warehouse_id' => $r->warehouse_id,
            'product_uom_id' => (string) ProductUom::where('product_id', $r->product_id)->where('is_base', true)->value('id'),
            'min' => Fields::qty($r->min_qty), 'max' => Fields::qty($r->max_qty)] + $this->productUnits($r->product_id);
    }

    // ---- What to order ---------------------------------------------------------------------------------------------

    /** The store to order for: the chosen one if the person may order there, else their first. */
    public function orderWarehouse(?string $id): ?Warehouse
    {
        $mine = Fields::warehouses('purchasing.manage');

        return Warehouse::find($id && isset($mine[$id]) ? $id : array_key_first($mine));
    }

    /**
     * Every line to order for the store, with the buyer's changes (product id → supplier_id, product_uom_id, qty, cost).
     *
     * @param  array<string, array<string, ?string>>  $changes
     */
    public function suggestions(Warehouse $wh, ?string $supplier, array $changes): array
    {
        $levels = Erp::can('stock.levels', $wh) || Erp::can('warehouse.manage', $wh);
        $currency = $this->user->tenant?->currency_code ?? 'GHS';
        $rows = collect(app(ReorderService::class)->suggestions($wh))
            ->map(fn ($s) => isset($changes[$s['product_id']]) ? ReorderService::change($s, $changes[$s['product_id']]) : $s + ['changed' => false, 'cost' => null])
            ->filter(fn ($s) => ! $supplier || ($supplier === 'none' ? $s['supplier_id'] === null : $s['supplier_id'] === $supplier))->values();

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency), 'warehouse' => $wh->id, 'can_levels' => $levels,
            'rows' => $rows->map(fn ($s) => [
                'id' => $s['product_id'], 'product_id' => $s['product_id'], 'name' => $s['name'], 'sku' => $s['sku'], 'changed' => (bool) ($s['changed'] ?? false),
                'on_hand' => Fields::qty(Qty::format($s['on_hand'])), 'on_order' => Fields::qty(Qty::format($s['on_order'])), 'in_transit' => Fields::qty(Qty::format($s['in_transit'])),
                'min' => Fields::qty(Qty::format($s['min'])), 'max' => Fields::qty(Qty::format($s['max'])), 'source' => ReorderLevels::SOURCES[$s['source']] ?? null,
                'move' => $s['move_qty'] ? Fields::qty(Qty::format($s['move_qty']), $s['base_uom_code']).' from '.$s['move_from'] : null,
                'supplier_id' => $s['supplier_id'], 'supplier' => $s['supplier'], 'product_uom_id' => $s['product_uom_id'], 'uom_code' => $s['uom_code'],
                'qty' => Fields::qty(Qty::format($s['suggest_qty'])), 'order' => $s['suggest_qty'] ? Fields::qty(Qty::format($s['suggest_qty']), $s['uom_code']) : null,
                'cost' => $s['cost'] ?? null, 'unit_cost' => $s['unit_cost_minor'] === null ? null : (int) $s['unit_cost_minor'],
            ])->all(),
        ];
    }

    public function orderOptions(): array
    {
        return [
            'warehouses' => PurchaseOrdersScreen::choices(Fields::warehouses('purchasing.manage')),
            'suppliers' => [['id' => 'none', 'label' => 'No supplier set up'], ...PurchaseOrdersScreen::choices(Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())],
        ];
    }

    /** For a line's change: this product's suppliers first (with their last cost), every other active supplier, and its units. */
    public function lineChoices(string $productId): array
    {
        $currency = $this->user->tenant?->currency_code ?? 'GHS';
        $theirs = SupplierProduct::with('supplier')->where('product_id', $productId)->get()->filter(fn ($sp) => $sp->supplier?->is_active)
            ->mapWithKeys(fn ($sp) => [$sp->supplier_id => $sp->supplier->name.($sp->last_cost_minor !== null ? ' — last '.Money::display((int) $sp->last_cost_minor, $currency) : '')])->all();
        $suppliers = $theirs + Supplier::where('is_active', true)->whereNotIn('id', array_keys($theirs))->orderBy('name')->pluck('name', 'id')->all();
        $preferred = SupplierProduct::where('product_id', $productId)->orderByDesc('is_preferred')->get(['supplier_id', 'product_uom_id'])
            ->mapWithKeys(fn ($sp) => [$sp->supplier_id => $sp->product_uom_id])->all();

        return [
            'suppliers' => PurchaseOrdersScreen::choices($suppliers),
            'units' => ProductUom::with('uom')->where('product_id', $productId)->get()->map(fn ($u) => ['id' => $u->id, 'label' => Fields::unitLabel($u)])->values()->all(),
            'supplier_units' => $preferred, // the unit each supplier sells it in
        ];
    }

    // ---- Suggested levels -------------------------------------------------------------------------------------------

    public function suggested(?string $warehouse): array
    {
        $warehouses = array_keys(self::levelWarehouses());
        $rows = DB::table('reorder_level_suggestions as s')->join('products as p', 'p.id', '=', 's.product_id')->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->whereIn('s.warehouse_id', $warehouse && in_array($warehouse, $warehouses, true) ? [$warehouse] : $warehouses)->orderBy('p.name')
            ->get(['s.id', 's.warehouse_id', 's.product_id', 's.min_qty', 's.max_qty', 's.avg_daily', 's.lead_days', 'p.sku', 'p.name', 'w.code as warehouse']);
        $current = [];
        foreach ($rows->groupBy('warehouse_id') as $whId => $list) {
            $current[$whId] = app(ReorderLevels::class)->forWarehouse(Warehouse::find($whId), $list->pluck('product_id')->all());
        }
        $units = ReorderLevels::buyingUnits($rows->pluck('product_id')->unique()->values()->all());
        $in = fn ($r, $v) => ReorderRuleResource::inBuyingUnit($r->product_id, $v, $units[$r->product_id] ?? null);

        return [
            'warehouses' => PurchaseOrdersScreen::choices(self::levelWarehouses()),
            'rows' => $rows->map(function ($r) use ($current, $in) {
                $now = $current[$r->warehouse_id][$r->product_id] ?? null;

                return ['id' => $r->id, 'name' => $r->name, 'sku' => $r->sku, 'store' => $r->warehouse, 'avg_daily' => Fields::qty((string) $r->avg_daily), 'lead_days' => $r->lead_days,
                    'now' => $now ? $in($r, Qty::format($now['min'])).' / '.$in($r, Qty::format($now['max'])) : 'none',
                    'now_source' => $now ? (ReorderLevels::SOURCES[$now['source']] ?? null) : null,
                    'suggested' => $in($r, $r->min_qty).' / '.$in($r, $r->max_qty)];
            })->values()->all(),
        ];
    }
}
