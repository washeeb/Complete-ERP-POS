<?php

namespace App\Domain\Office;

use App\Domain\Inventory\InventoryService;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\IndustryContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stock on hand and the stock ledger in the new back office (release 3, part 3): the current screens' lists, filters
 * and actions, over exactly the same records — the queries are the current screens' own (StockLevelResource and
 * StockMovementResource::getEloquentQuery), so the warehouses and industries a person sees cannot differ.
 */
final class StockScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return Erp::canAnywhere('stock.view');
    }

    private static function opt(array $rows): array
    {
        return collect($rows)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private function page(array $p, string $default = 'asc'): array
    {
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;

        return [$per, max(1, (int) ($p['page'] ?? 1)), ($p['dir'] ?? $default) === 'desc' ? 'desc' : 'asc'];
    }

    /** Products matching what is typed (SKU, name, or an exact barcode), as a subquery of ids. */
    private static function productsMatching(string $q): Builder
    {
        return Product::query()->select('id')->where(fn ($w) => $w->where('sku', 'ilike', "%{$q}%")->orWhere('name', 'ilike', "%{$q}%")
            ->orWhereHas('barcodes', fn ($b) => $b->where('code', $q)));
    }

    /** The stock lines the search and filters pick (also for the export). */
    public function levelsFiltered(array $p): Builder
    {
        $q = StockLevelResource::getEloquentQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->whereIn('product_id', self::productsMatching($s));
        }
        if (filled($p['warehouse'] ?? null)) {
            $q->where('warehouse_id', (string) $p['warehouse']);
        }
        if (filled($p['category'] ?? null)) {
            $q->whereIn('product_id', Product::select('id')->where('category_id', (string) $p['category']));
        }
        match ($p['level'] ?? null) {
            'in' => $q->where('on_hand', '>', 0), 'out' => $q->where('on_hand', 0), 'negative' => $q->where('on_hand', '<', 0), default => null,
        };

        return $q;
    }

    /** [sort, direction] of the stock list. */
    private function levelsOrder(array $p): array
    {
        return [in_array($p['sort'] ?? null, ['product', 'on_hand', 'updated_at'], true) ? $p['sort'] : 'product', ($p['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc'];
    }

    public function levelsSorted(array $p): Builder
    {
        [$sort, $dir] = $this->levelsOrder($p);
        $q = $this->levelsFiltered($p)->with('product.baseUom', 'warehouse');
        match ($sort) {
            'on_hand' => $q->orderBy('on_hand', $dir),
            'updated_at' => $q->orderByRaw('updated_at '.$dir.' NULLS LAST'),
            default => $q->orderBy(Product::select('name')->whereColumn('products.id', 'stock_levels.product_id'), $dir),
        };

        return $q->orderBy('warehouse_id')->orderBy('product_id');
    }

    /** One line of stock on hand. */
    public function levelRow(StockLevel $l): array
    {
        return [
            'id' => "{$l->warehouse_id}:{$l->product_id}", 'product_id' => $l->product_id, 'sku' => $l->product?->sku, 'name' => $l->product?->name,
            'warehouse' => $l->warehouse ? "{$l->warehouse->code} · {$l->warehouse->name}" : null, 'on_hand' => ProductsScreen::qty((string) $l->on_hand),
            'unit' => $l->product?->baseUom?->code, 'updated_at' => $l->updated_at?->toIso8601String(),
        ];
    }

    /** @param  array<string, mixed>  $p  q, warehouse, category, level (in|out|negative), sort (product|on_hand|updated_at), dir, page, per */
    public function levels(array $p): array
    {
        $q = $this->levelsFiltered($p);
        $totals = (clone $q)->toBase()->selectRaw('COUNT(*) AS n, COUNT(*) FILTER (WHERE on_hand > 0) AS stocked, COUNT(*) FILTER (WHERE on_hand = 0) AS empty, COUNT(*) FILTER (WHERE on_hand < 0) AS negative')->first();
        [$per, $page] = $this->page($p);
        [$sort, $dir] = $this->levelsOrder($p);
        $rows = $this->levelsSorted($p)->forPage($page, $per)->get();

        return [
            'total' => (int) $totals->n, 'stocked' => (int) $totals->stocked, 'empty' => (int) $totals->empty, 'negative' => (int) $totals->negative,
            'page' => $page, 'per' => $per, 'sort' => $sort, 'dir' => $dir,
            'rows' => $rows->map(fn (StockLevel $l) => $this->levelRow($l))->all(),
        ];
    }

    /** What the stock on hand screen offers: filters, the stock sheet, and the adjustment form. */
    public function levelsOptions(): array
    {
        $adjust = Erp::canAnywhere('stock.adjust');

        return [
            'warehouses' => self::opt(Fields::warehouses('stock.view')),
            'categories' => self::opt(Category::orderBy('name')->pluck('name', 'id')->all()),
            'per' => self::PER_PAGE,
            'can_adjust' => $adjust,
            'adjust_warehouses' => $adjust ? self::opt(Fields::warehouses('stock.adjust')) : [],
            'reasons' => self::opt(InventoryService::ADJUSTMENT_REASONS),
            'links' => array_filter(['import' => $adjust ? '/office/import?type=stock_adjustment' : null]),
        ];
    }

    /**
     * Product units for a document line (adjustments, counts, transfers…): products by name, SKU or a scanned barcode, as
     * the current forms' "Product and unit" (Fields::productUnit), with whether they take batches or serial numbers.
     * $buying: only the units the product is bought in.
     */
    public function units(string $q, bool $buying = false): array
    {
        if (mb_strlen($q) < 2) {
            return [];
        }

        return ProductUom::with('product', 'uom')
            ->whereHas('product', fn (Builder $w) => IndustryContext::productScope($w)->whereNull('products.archived_at')->where('track_stock', true))
            ->where(fn ($w) => $w->whereHas('product', fn (Builder $x) => $x->where('is_active', true)->where(fn ($y) => $y->where('name', 'ilike', "%{$q}%")
                ->orWhere('sku', 'ilike', "%{$q}%")))->orWhereHas('barcodes', fn ($b) => $b->where('code', $q)))
            ->when($buying, fn ($w) => $w->where('is_purchasable', true))
            ->limit(30)->get()->sortBy(fn ($u) => [$u->product->name, -(float) $u->factor])->values()
            ->map(fn (ProductUom $u) => ['id' => $u->id, 'product_id' => $u->product_id, 'label' => Fields::unitLabel($u, $buying), 'batches' => (bool) $u->product->track_batches,
                'serialised' => (bool) $u->product->track_serials])->all();
    }

    /** What a warehouse holds of a product unit now, its average cost per base unit, and a batch's share. */
    public function unitStock(string $unitId, string $warehouseId, ?string $batch): ?array
    {
        $ids = StockLevelResource::warehouseIds();
        if ($ids !== null && ! in_array($warehouseId, $ids, true)) {
            return null;
        }
        $s = Fields::stockOf($unitId, $warehouseId, $batch);

        return $s ? ['qty' => round($s['qty'], 3), 'unit' => $s['unit'], 'factor' => $s['factor'], 'cost' => $s['cost'], 'batch' => $s['batch'] === null ? null : round($s['batch'], 3)] : null;
    }

    /** Stocked products matching what is typed, with their units, for the adjustment form. */
    public function find(string $q): array
    {
        if (mb_strlen($q) < 2) {
            return [];
        }

        return IndustryContext::productScope(Product::query())->where('track_stock', true)->whereIn('id', self::productsMatching($q))
            ->with('units.uom')->orderBy('name')->limit(20)->get()
            ->map(fn (Product $p) => ['id' => $p->id, 'label' => "{$p->sku} · {$p->name}", 'batches' => (bool) $p->track_batches,
                'units' => $p->units->sortByDesc('is_base')->values()->map(fn ($u) => ['id' => $u->id,
                    'label' => $u->uom->code.($u->is_base ? '' : ' (× '.rtrim(rtrim((string) $u->factor, '0'), '.').')')])->all()])->all();
    }

    /** The movements the search and filters pick (also for the export). */
    public function movementsFiltered(array $p): Builder
    {
        $tz = $this->user->tenant?->timezone ?: 'UTC';
        $today = CarbonImmutable::now($tz)->startOfDay();
        $q = StockMovementResource::getEloquentQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->whereIn('product_id', self::productsMatching($s));
        }
        if (filled($p['product'] ?? null)) {
            $q->where('product_id', (string) $p['product']);
        }
        $period = (string) ($p['period'] ?? '');
        if ($period === 'today') {
            $q->where('occurred_at', '>=', $today->utc());
        } elseif (in_array($period, ['7', '30', '90'], true)) {
            $q->where('occurred_at', '>=', $today->subDays((int) $period - 1)->utc());
        }
        foreach (['from' => '>=', 'to' => '<'] as $k => $op) {
            $d = $p[$k] ?? null;
            if (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $at = CarbonImmutable::createFromFormat('Y-m-d', $d, $tz)->startOfDay();
                $q->where('occurred_at', $op, ($k === 'to' ? $at->addDay() : $at)->utc());
            }
        }
        $types = array_values(array_intersect((array) ($p['types'] ?? []), array_keys(StockMovementResource::TYPES)));
        if ($types) {
            $q->whereIn('type', $types);
        }
        if ($w = array_filter((array) ($p['warehouses'] ?? []), 'is_string')) {
            $q->whereIn('warehouse_id', $w);
        }
        if ($c = array_filter((array) ($p['categories'] ?? []), 'is_string')) {
            $q->whereIn('product_id', Product::select('id')->whereIn('category_id', $c));
        }
        if ($b = array_filter((array) ($p['brands'] ?? []), 'is_string')) {
            $q->whereIn('product_id', Product::select('id')->whereIn('brand_id', $b));
        }
        if (filled($p['by'] ?? null)) {
            $q->where('actor_id', (string) $p['by']);
        }

        return $q;
    }

    public function movementsSorted(array $p): Builder
    {
        $dir = ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $this->movementsFiltered($p)->with('product:id,name,sku,base_uom_id', 'product.baseUom:id,code')->orderBy('occurred_at', $dir)->orderBy('id', $dir);
    }

    /** One movement of the ledger. */
    public function movementRow(StockMovement $m): array
    {
        return [
            'id' => $m->id, 'at' => $m->occurred_at?->toIso8601String(), 'warehouse' => $m->warehouse?->code, 'product_id' => $m->product_id,
            'product' => $m->product?->name, 'sku' => $m->product?->sku, 'type' => StockMovementResource::TYPES[$m->type] ?? $m->type,
            'entered' => ProductsScreen::qty((string) $m->qty_entered).' '.$m->uom?->code, 'qty' => ProductsScreen::qty((string) $m->qty_base),
            'unit' => $m->product?->baseUom?->code, 'in' => (float) $m->qty_base >= 0, 'reason' => $m->reason, 'by' => $m->actor_name,
        ];
    }

    /**
     * @param  array<string, mixed>  $p  q (product), product (id), period (today|7|30|90), from, to, types[], warehouses[], categories[], brands[], by,
     *                                   dir, page, per
     */
    public function movements(array $p): array
    {
        $total = $this->movementsFiltered($p)->count();
        [$per, $page, $dir] = $this->page($p, 'desc');
        $rows = $this->movementsSorted($p)->forPage($page, $per)->get();

        return [
            'total' => $total, 'page' => $page, 'per' => $per, 'sort' => 'occurred_at', 'dir' => $dir,
            'product' => filled($p['product'] ?? null) ? Product::whereKey((string) $p['product'])->value('name') : null,
            'rows' => $rows->map(fn (StockMovement $m) => $this->movementRow($m))->all(),
        ];
    }

    public function movementsOptions(): array
    {
        return [
            'types' => self::opt(StockMovementResource::TYPES),
            'warehouses' => self::opt(Fields::warehouses('stock.view')),
            'categories' => self::opt(Category::orderBy('name')->pluck('name', 'id')->all()),
            'brands' => self::opt(Brand::orderBy('name')->pluck('name', 'id')->all()),
            'people' => StockMovementResource::getEloquentQuery()->toBase()->whereNotNull('actor_id')->groupBy('actor_id')
                ->selectRaw('actor_id, MAX(actor_name) AS name')->orderBy('name')->limit(300)->get()
                ->map(fn ($r) => ['id' => $r->actor_id, 'label' => $r->name])->all(),
            'per' => self::PER_PAGE,
        ];
    }
}
