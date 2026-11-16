<?php

namespace App\Domain\Office;

use App\Domain\Catalog\Labels;
use App\Domain\Modules\ModuleService;
use App\Filament\Pages\ImportData;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\StockCostRuns\StockCostRunResource;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Support\Erp;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Industry;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\TaxGroup;
use App\Models\Uom;
use App\Models\User;
use App\Support\IndustryContext;
use App\Support\Money;
use App\Support\Qty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Products in the new back office (release 3 of the rewrite): the list with the current screen's search (SKU, name, or
 * a scanned barcode), filters and columns, and one product on a page of its own (details, units and barcodes, prices,
 * stock where it is, batches, latest movements). Only products of the person's industries; stock only in the branches
 * whose stock they may see. Changes go through the same services as the current screen (ProductsController).
 */
final class ProductsScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const SORTS = ['name', 'sku'];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return Erp::canAnywhere('catalog.view');
    }

    public function query(): Builder
    {
        return IndustryContext::productScope(Product::query());
    }

    /** Warehouses whose stock this person may see: null = all, [] = none. */
    private function warehouses(): ?array
    {
        return Erp::canAnywhere('stock.view') ? StockLevelResource::warehouseIds() : [];
    }

    /** SQL for the stock on hand of `products.id` in the warehouses this person may see. */
    private function onHandSql(): array
    {
        $ids = $this->warehouses();
        if ($ids === []) {
            return ['NULL', []];
        }
        $in = $ids === null ? '' : ' AND warehouse_id IN ('.implode(',', array_fill(0, count($ids), '?')).')';

        return ["(SELECT COALESCE(SUM(on_hand), 0) FROM stock_levels WHERE stock_levels.product_id = products.id{$in})", $ids ?? []];
    }

    /** The products the list's search and filters pick (also for "every matching product" in the actions). */
    public function filtered(array $p): Builder
    {
        $q = $this->query();
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn (Builder $w) => $w->where('sku', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%")
                ->orWhere('location', 'ilike', "%{$search}%")->orWhereHas('barcodes', fn ($b) => $b->where('code', $search)));
        }
        $industry = (string) ($p['industry'] ?? '');
        if ($industry === IndustryContext::SHARED) {
            $q->whereNull('industry_id');
        } elseif ($industry !== '') {
            $q->where('industry_id', $industry);
        }
        if (filled($p['category'] ?? null)) {
            $q->where('category_id', (string) $p['category']);
        }
        if (in_array($p['active'] ?? null, ['1', '0'], true)) {
            $q->where('is_active', $p['active'] === '1');
        }
        $schedule = (string) ($p['schedule'] ?? '');
        if ($schedule === 'none') {
            $q->whereNull('drug_schedule');
        } elseif (isset(Product::SCHEDULES[$schedule])) {
            $q->where('drug_schedule', $schedule);
        }
        [$onHand, $bindings] = $this->onHandSql();
        if ($onHand !== 'NULL' && ($p['stock'] ?? null) === 'out') {
            $q->where('track_stock', true)->whereRaw("{$onHand} <= 0", $bindings);
        }

        return $q;
    }

    /** @param  array<string, mixed>  $p  q, industry, category, active (1|0), schedule, stock (out), sort, dir, page, per */
    public function list(array $p): array
    {
        $q = $this->filtered($p);
        [$onHand, $bindings] = $this->onHandSql();
        $stockShown = $onHand !== 'NULL';

        $totals = (clone $q)->selectRaw('COUNT(*) AS n, COUNT(*) FILTER (WHERE is_active) AS active')->first();
        $out = $stockShown ? (clone $q)->where('track_stock', true)->whereRaw("{$onHand} <= 0", $bindings)->count() : null;

        $sort = in_array($p['sort'] ?? null, self::SORTS, true) ? $p['sort'] : 'name';
        $dir = ($p['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $default = PriceList::where('is_default', true)->first();
        $rows = (clone $q)->select('products.*')->selectRaw("{$onHand} AS on_hand_sum", $bindings)
            ->with(['category:id,name', 'industry:id,name', 'baseUom:id,code', 'units:id,product_id,is_base', 'prices'])
            ->orderBy($sort, $dir)->orderBy('id', $dir)->forPage($page, $per)->get();

        return [
            'currency' => $default?->currency_code ?? $this->currency(), 'exponent' => Money::exponent($default?->currency_code ?? $this->currency()),
            'price_list' => $default?->name, 'stock_shown' => $stockShown,
            'total' => (int) $totals->n, 'active' => (int) $totals->active, 'out' => $out,
            'page' => $page, 'per' => $per, 'sort' => $sort, 'dir' => $dir,
            'rows' => $rows->map(fn (Product $r) => [
                'id' => $r->id, 'sku' => $r->sku, 'name' => $r->name, 'category' => $r->category?->name, 'location' => $r->location,
                'schedule' => $r->drug_schedule, 'industry' => $r->industry?->name, 'active' => (bool) $r->is_active, 'batches' => (bool) $r->track_batches,
                'price' => $this->basePrice($r, $default),
                'on_hand' => $stockShown && $r->track_stock ? self::qty((string) $r->on_hand_sum) : null,
                'unit' => $r->baseUom?->code, 'tracked' => (bool) $r->track_stock,
            ])->all(),
        ];
    }

    /** The standard price of the base unit on the default list (from 1, not a promotion), as the current list shows. */
    private function basePrice(Product $r, ?PriceList $default): ?int
    {
        $base = $r->units->firstWhere('is_base', true)?->id;
        $p = $r->prices->first(fn ($x) => $x->price_list_id === $default?->id && $x->product_uom_id === $base && Qty::toMilli((string) $x->min_qty) === 1000 && ! $x->starts_at);

        return $p ? (int) $p->amount_minor : null;
    }

    /** What the filters and the actions offer. */
    public function options(): array
    {
        $manage = Erp::canAnywhere('catalog.manage');
        $pharmacy = ModuleService::enabledAnywhere('pharmacy');
        $industries = Industry::licensed()->exists();

        return [
            'industries' => $industries ? [...Industry::licensed()->orderBy('sort')->get(['id', 'name'])->map(fn ($i) => ['id' => $i->id, 'label' => $i->name])->all(),
                ['id' => IndustryContext::SHARED, 'label' => 'Shared by all industries']] : [],
            'categories' => Category::orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'label' => $c->name])->all(),
            'schedules' => $pharmacy ? [...collect(Product::SCHEDULES)->map(fn ($l, $k) => ['id' => $k, 'label' => $l])->values()->all(), ['id' => 'none', 'label' => 'Not a medicine']] : [],
            'per' => self::PER_PAGE,
            'can' => [
                'manage' => $manage, 'prices' => Erp::canAnywhere('pricing.manage'), 'labels' => true,
                'cost_run' => StockCostRunResource::canCreate(), 'schedule' => $pharmacy && $manage,
                'move_industry' => $industries && $manage, 'stock' => Erp::canAnywhere('stock.view'),
            ],
            // The current back office's pages these lead to (opened inside the new one).
            'links' => array_filter([
                'create' => $manage ? self::path(ProductResource::getUrl('create')) : null,
                'import' => $manage ? self::path(ImportData::getUrl(['type' => 'products'])) : null,
                'update' => $manage ? self::path(ImportData::getUrl(['type' => 'products_update'])) : null,
                'import_prices' => Erp::canAnywhere('pricing.manage') ? self::path(ImportData::getUrl(['type' => 'prices'])) : null,
            ]),
            'batches_off' => $manage ? Product::where('track_stock', true)->where('track_batches', false)->count() : 0,
            'batches_note' => ProductResource::BATCHES_NOTE,
            // For the actions' forms.
            'labels' => [
                'kinds' => collect(Labels::KINDS)->map(fn ($l, $k) => ['id' => $k, 'label' => $l])->values()->all(),
                'layouts' => collect(Labels::LAYOUTS)->map(fn ($l, $k) => ['id' => $k, 'label' => $l[0], 'sheet' => $l[2] > 0])->values()->all(),
                'price_lists' => PriceList::where('is_active', true)->orderByDesc('is_default')->get(['id', 'name'])->map(fn ($l) => ['id' => $l->id, 'label' => $l->name])->all(),
            ],
            'brands' => $manage ? Brand::orderBy('name')->get(['id', 'name'])->map(fn ($b) => ['id' => $b->id, 'label' => $b->name])->all() : [],
            'tax_groups' => $manage ? TaxGroup::orderBy('name')->get(['id', 'name'])->map(fn ($t) => ['id' => $t->id, 'label' => $t->name])->all() : [],
            'uoms' => $manage ? Uom::whereNull('merged_into_id')->orderBy('code')->get(['id', 'code', 'name'])->map(fn ($u) => ['id' => $u->id, 'label' => "{$u->code} · {$u->name}"])->all() : [],
            'industry_choices' => $industries && $manage ? collect(IndustryContext::choices($this->user))->map(fn ($l, $k) => ['id' => (string) $k, 'label' => $l])->values()->all() : [],
            'medicine_schedules' => collect(Product::SCHEDULES + ['none' => 'Not a medicine'])->map(fn ($l, $k) => ['id' => $k, 'label' => $l])->values()->all(),
        ];
    }

    /** One product: what the current form shows, read-only, plus where its stock is and what last happened to it. */
    public function show(Product $p): array
    {
        $p->loadMissing(['category', 'brand', 'taxGroup', 'baseUom', 'industry', 'units.uom', 'units.barcodes', 'prices.priceList', 'prices.productUom.uom']);
        $currency = $this->currency();
        $pharmacy = ModuleService::enabledAnywhere('pharmacy');
        $buying = $p->buying_uom_id ? Uom::find($p->buying_uom_id) : null;
        $yes = fn ($v) => $v ? 'Yes' : 'No';

        $facts = array_filter([
            'SKU' => $p->sku, 'Category' => $p->category?->name ?? '—', 'Brand' => $p->brand?->name ?? '—', 'Tax' => $p->taxGroup?->name ?? '—',
            'Base unit (stock is kept in this)' => $p->baseUom ? "{$p->baseUom->code} · {$p->baseUom->name}" : '—',
            'Usually bought in' => $buying ? "{$buying->code} · {$buying->name}" : '—',
            'Location / shelf' => $p->location ?: '—',
            'Industry' => Industry::licensed()->exists() ? ($p->industry?->name ?? 'Shared by all industries') : null,
            'Track stock' => $yes($p->track_stock), 'Batches and expiry dates' => $yes($p->track_batches),
            'Scale code (PLU)' => $p->plu ?: null,
        ], fn ($v) => $v !== null);
        $medicine = $pharmacy || $p->drug_schedule ? array_filter([
            'Schedule' => Product::SCHEDULES[$p->drug_schedule] ?? 'Not a medicine', 'Generic name' => $p->generic_name, 'Strength' => $p->strength,
            'Form' => $p->dosage_form, 'FDA registration no.' => $p->fda_reg_no,
            'Active ingredients' => $p->active_ingredients ? implode(', ', (array) $p->active_ingredients) : null,
            'Usual dosage' => $p->dosage,
            'NHIS price (per base unit)' => $p->nhis_price_minor !== null ? Money::display((int) $p->nhis_price_minor, $currency) : null,
            'Used a day (refill reminders)' => $p->daily_use !== null ? self::qty((string) $p->daily_use) : null,
        ], fn ($v) => $v !== null && $v !== '') : [];

        $canPrices = Erp::canForIndustry('pricing.manage', $p->industry_id);
        $warehouses = $this->warehouses();
        $stockShown = $warehouses !== [];
        $inSight = fn ($q) => $q->when($warehouses !== null, fn ($w) => $w->whereIn('warehouse_id', $warehouses));

        $stock = $stockShown && $p->track_stock ? $inSight(DB::table('stock_levels')->where('product_id', $p->id))
            ->join('warehouses', 'warehouses.id', '=', 'stock_levels.warehouse_id')->join('branches', 'branches.id', '=', 'warehouses.branch_id')
            ->orderBy('branches.code')->orderBy('warehouses.code')
            ->get(['warehouses.code AS wh', 'warehouses.name AS wh_name', 'branches.code AS branch', 'stock_levels.on_hand'])
            ->map(fn ($r) => ['where' => "{$r->branch} · {$r->wh} {$r->wh_name}", 'on_hand' => self::qty((string) $r->on_hand), 'low' => Qty::toMilli((string) $r->on_hand) <= 0])->all() : [];
        $total = $stockShown && $p->track_stock ? self::qty((string) $inSight(DB::table('stock_levels')->where('product_id', $p->id))->sum('on_hand')) : null;

        $batches = $stockShown && $p->track_batches ? $inSight(DB::table('stock_batch_levels')->where('stock_batch_levels.product_id', $p->id))
            ->join('stock_batches', 'stock_batches.id', '=', 'stock_batch_levels.batch_id')
            ->groupBy('stock_batches.id', 'stock_batches.batch_no', 'stock_batches.expires_on')->havingRaw('SUM(stock_batch_levels.on_hand) <> 0')
            ->orderByRaw('stock_batches.expires_on NULLS LAST')->limit(50)
            ->get(['stock_batches.batch_no', 'stock_batches.expires_on', DB::raw('SUM(stock_batch_levels.on_hand) AS on_hand')])
            ->map(fn ($b) => ['batch' => $b->batch_no, 'expires' => $b->expires_on, 'on_hand' => self::qty((string) $b->on_hand),
                'expired' => $b->expires_on !== null && $b->expires_on < now()->toDateString()])->all() : [];

        $movements = $stockShown && $p->track_stock ? $inSight(StockMovement::query()->where('product_id', $p->id))->with('warehouse:id,code')
            ->orderByDesc('occurred_at')->limit(15)->get()
            ->map(fn (StockMovement $m) => ['at' => $m->occurred_at?->toIso8601String(), 'type' => StockMovementResource::TYPES[$m->type] ?? $m->type,
                'qty' => self::qty((string) $m->qty_base), 'in' => (float) $m->qty_base >= 0, 'where' => $m->warehouse?->code, 'reason' => $m->reason, 'by' => $m->actor_name])->all() : [];

        $manage = Erp::canAnywhere('catalog.manage');

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency),
            'id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'active' => (bool) $p->is_active, 'schedule' => $p->drug_schedule,
            'description' => $p->description, 'unit' => $p->baseUom?->code, 'tracked' => (bool) $p->track_stock,
            'facts' => $facts, 'medicine' => $medicine,
            'units' => $p->units->sortByDesc('is_base')->values()->map(fn ($u) => [
                'unit' => $u->uom ? "{$u->uom->code} · {$u->uom->name}" : '—', 'factor' => self::qty(number_format((float) $u->factor, 3, '.', '')),
                'base' => (bool) $u->is_base, 'sold' => (bool) $u->is_sellable, 'bought' => (bool) $u->is_purchasable, 'barcodes' => $u->barcodes->pluck('code')->all(),
            ])->all(),
            'prices' => $p->prices->sortBy([fn ($a, $b) => ($b->priceList?->is_default <=> $a->priceList?->is_default), fn ($a, $b) => $a->min_qty <=> $b->min_qty])->values()
                ->map(fn ($x) => ['list' => $x->priceList?->name, 'unit' => $x->productUom?->uom?->code, 'from' => self::qty((string) $x->min_qty), 'amount' => (int) $x->amount_minor,
                    'starts' => $x->starts_at?->toIso8601String(), 'ends' => $x->ends_at?->toIso8601String()])->all(),
            'pricing_note' => $canPrices ? ProductResource::pricingNote($p) : null,
            'below_cost' => $canPrices && ProductResource::belowCost($p),
            'stock_shown' => $stockShown, 'stock' => $stock, 'stock_total' => $total, 'batches' => $batches, 'movements' => $movements,
            'actions' => ['edit' => $manage ? self::path(ProductResource::getUrl('edit', ['record' => $p])) : null, 'labels' => true, 'change_unit' => $manage],
            'change_unit_note' => $manage ? 'Counted in '.$p->baseUom?->code.' now, '.($total ?? self::qty((string) DB::table('stock_levels')->where('product_id', $p->id)->sum('on_hand')))
                .' in stock. Past receipts and documents keep what they showed.' : null,
        ];
    }

    /** https://host/admin/x?y=1 → /admin/x?y=1 */
    public static function path(string $url): string
    {
        $q = parse_url($url, PHP_URL_QUERY);

        return parse_url($url, PHP_URL_PATH).($q ? "?{$q}" : '');
    }

    /** 12.500 → "12.5", 40.000 → "40". */
    public static function qty(string $v): string
    {
        $f = Qty::format((int) round(((float) $v) * 1000));

        return rtrim(rtrim($f, '0'), '.');
    }

    private function currency(): string
    {
        return $this->user->tenant?->currency_code ?? 'GHS';
    }
}
