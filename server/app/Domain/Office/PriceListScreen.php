<?php

namespace App\Domain\Office;

use App\Domain\Inventory\StockProjection;
use App\Domain\Pricing\TaxCalculator;
use App\Filament\Pages\PriceListExport;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Price;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The price list in the new back office (catalog and pricing setup, part 10 of the rewrite), as the current screen:
 * every product in every unit with its price on a chosen list (before tax, VAT and levies, what the customer pays),
 * narrowed by category, brand, supplier, product, status, in stock and priced, then exported (Excel, CSV, or a PDF
 * with the company's logo, through ListExport). Cost and margin only for people who may set prices, so a list printed
 * by anyone else can go to a customer. "Export prices" on the Products list brings the ticked products (only=1).
 */
final class PriceListScreen
{
    public const PER_PAGE = [25, 50, 100, 200];

    /** Products ticked on the Products list (the hand-off keeps them in the session: hundreds are too long for an address). */
    public const SESSION = 'price_list.products';

    private ?PriceList $list = null;

    private ?array $prices = null;

    private ?array $stock = null;

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return PriceListExport::canAccess();
    }

    public static function mayCost(): bool
    {
        return Erp::canAnywhere('pricing.manage');
    }

    private static function pairs(array $a): array
    {
        return array_map(fn ($k, $v) => ['id' => (string) $k, 'label' => (string) $v], array_keys($a), $a);
    }

    public function options(): array
    {
        return ['lists' => self::pairs(PriceList::where('is_active', true)->orderByDesc('is_default')->orderBy('name')->pluck('name', 'id')->all()),
            'default_list' => PriceList::where('is_default', true)->value('id'),
            'categories' => self::pairs(Category::orderBy('name')->pluck('name', 'id')->all()), 'brands' => self::pairs(Brand::orderBy('name')->pluck('name', 'id')->all()),
            'suppliers' => self::pairs(Supplier::orderBy('name')->pluck('name', 'id')->all()), 'may_cost' => self::mayCost()];
    }

    public function products(string $q): array
    {
        $q = trim($q);

        return $q === '' ? [] : self::pairs(Product::where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('sku', 'ilike', "%{$q}%"))->orderBy('name')->limit(30)->pluck('name', 'id')->all());
    }

    /** @param  array<string, mixed>  $p  list */
    public function list(array $p): ?PriceList
    {
        $id = (string) ($p['list'] ?? '');

        return $this->list ??= ($id !== '' ? PriceList::find($id) : null) ?? PriceList::where('is_default', true)->first();
    }

    /** @return list<string> */
    public static function ticked(array $p): array
    {
        return ($p['only'] ?? '') === '1' ? array_values((array) session(self::SESSION, [])) : [];
    }

    /** Today's single-unit price per product unit on the list. */
    private function prices(array $p): array
    {
        $list = $this->list($p);
        if (! $list) {
            return [];
        }
        $now = now();

        return $this->prices ??= Price::where('price_list_id', $list->id)->where('min_qty', '<=', 1)->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))->orderBy('min_qty')->orderByRaw('starts_at IS NOT NULL')->pluck('amount_minor', 'product_uom_id')->all();
    }

    /** Stock and average cost per product, in the stores this person may see. */
    private function stock(): array
    {
        $branches = Erp::branchIds('catalog.view');

        return $this->stock ??= DB::table('stock_levels as l')->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')->when($branches !== null, fn ($q) => $q->whereIn('w.branch_id', $branches))
            ->groupBy('l.product_id')->selectRaw('l.product_id, SUM(l.on_hand) AS on_hand, SUM(l.on_hand * l.avg_cost_minor) / NULLIF(SUM(CASE WHEN l.avg_cost_minor IS NOT NULL THEN l.on_hand END), 0) AS cost')
            ->get()->keyBy('product_id')->all();
    }

    /** @param  array<string, mixed>  $p  q, list, category[], brand[], supplier[], product[], active (1 | 0 | all), in_stock, priced, only */
    public function query(array $p): Builder
    {
        $many = fn (string $k) => array_values(array_filter((array) ($p[$k] ?? []), fn ($v) => is_string($v) && $v !== ''));
        $only = self::ticked($p);
        $active = (string) ($p['active'] ?? '1');
        $search = trim((string) ($p['q'] ?? ''));

        return ProductUom::query()->join('products as p', 'p.id', '=', 'product_uoms.product_id')->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->select('product_uoms.*')->where('product_uoms.is_sellable', true)->with('uom', 'product.category', 'product.brand', 'product.taxGroup.rates')
            ->when(($p['only'] ?? '') === '1', fn ($q) => $q->whereIn('product_uoms.product_id', $only))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('p.name', 'ilike', "%{$search}%")->orWhere('p.sku', 'ilike', "%{$search}%")))
            ->when($many('category'), fn ($q, $v) => $q->whereIn('p.category_id', $v))
            ->when($many('brand'), fn ($q, $v) => $q->whereIn('p.brand_id', $v))
            ->when($many('supplier'), fn ($q, $v) => $q->whereIn('product_uoms.product_id', DB::table('supplier_products')->whereIn('supplier_id', $v)->select('product_id')))
            ->when($many('product'), fn ($q, $v) => $q->whereIn('product_uoms.product_id', $v))
            ->when($active === '1' || $active === '0', fn ($q) => $q->where('p.is_active', $active === '1'))
            ->when(($p['in_stock'] ?? '') === '1', fn ($q) => $q->whereIn('product_uoms.product_id', DB::table('stock_levels')->where('on_hand', '>', 0)->select('product_id')))
            ->when(($p['priced'] ?? '') === '1', fn ($q) => $q->whereIn('product_uoms.id', array_keys($this->prices($p))))
            ->orderBy('c.name')->orderBy('p.name')->orderBy('product_uoms.factor')->orderBy('product_uoms.id');
    }

    /** @return array{net: int, tax: int, gross: int}|null the price, split, for one unit */
    private function amounts(ProductUom $u, array $p): ?array
    {
        $price = $this->prices($p)[$u->id] ?? null;
        if ($price === null) {
            return null;
        }
        $rates = $u->product->taxGroup?->rates->where('is_active', true)->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all() ?? [];

        return TaxCalculator::line((int) $price, $rates, (bool) $this->list($p)?->prices_include_tax);
    }

    private function cost(ProductUom $u): ?int
    {
        $avg = $this->stock()[$u->product_id]->cost ?? null;

        return $avg === null ? null : StockProjection::costPerUnit(number_format((float) $avg, 4, '.', ''), (string) $u->factor);
    }

    /** One row, as the current screen shows it (money in minor units; the client and the export format it). */
    public function rowOf(ProductUom $u, array $p): array
    {
        $a = $this->amounts($u, $p);
        $cost = self::mayCost() ? $this->cost($u) : null;
        $net = $a['net'] ?? null;

        return ['id' => $u->id, 'sku' => $u->product->sku, 'product' => $u->product->name, 'category' => $u->product->category?->name, 'brand' => $u->product->brand?->name,
            'unit' => $u->uom->code.((float) $u->factor != 1 ? ' × '.rtrim(rtrim((string) $u->factor, '0'), '.') : ''),
            'net' => $net, 'tax' => $a['tax'] ?? null, 'gross' => $a['gross'] ?? null,
            'on_hand' => $u->product->track_stock ? rtrim(rtrim(number_format((float) ($this->stock()[$u->product_id]->on_hand ?? 0) / (float) $u->factor, 3, '.', ''), '0'), '.') : null,
            'cost' => $cost, 'margin' => $net && $cost !== null ? round(($net - $cost) * 100 / $net).'%' : null];
    }

    /** @param  array<string, mixed>  $p  the filters, page, per */
    public function page(array $p): array
    {
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 50;
        $page = max(1, (int) ($p['page'] ?? 1));
        $q = $this->query($p);
        $currency = FinanceUi::currency();

        return ['total' => (clone $q)->toBase()->getCountForPagination(), 'page' => $page, 'per' => $per, 'currency' => $currency, 'exponent' => Money::exponent($currency),
            'list' => $this->list($p)?->name, 'list_id' => $this->list($p)?->id, 'ticked' => ($p['only'] ?? '') === '1' ? count(self::ticked($p)) : null, 'may_cost' => self::mayCost(),
            'rows' => $q->forPage($page, $per)->get()->map(fn (ProductUom $u) => $this->rowOf($u, $p))->all()];
    }
}
