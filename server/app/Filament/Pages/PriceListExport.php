<?php

namespace App\Filament\Pages;

use App\Domain\Inventory\StockProjection;
use App\Domain\Pricing\TaxCalculator;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Price;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Supplier;
use App\Support\RequestMemo;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Pricing & tax → Price list: every product in every unit with its price on a chosen list, narrowed by brand, category,
 * supplier and so on, then exported like any list (Excel, CSV, or a PDF with the company's logo). Cost and margin only
 * for people who may set prices, so a list printed by anyone else can go to a customer.
 */
class PriceListExport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = 'Pricing & tax';

    protected static ?string $navigationLabel = 'Price list';

    protected static ?string $title = 'Price list';

    protected static ?string $slug = 'price-list';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.table-page';

    /** Products ticked on the Products list: only these. */
    public array $only = [];

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('catalog.view');
    }

    public function mount(): void
    {
        $this->only = session()->pull('price_list.products', []);
    }

    public function getSubheading(): ?string
    {
        return ($this->only ? count($this->only).' product(s) ticked on the Products list. ' : '').'Choose what you want with the filters, then Export.';
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('all')->label('Show all products')->color('gray')->visible(fn () => $this->only !== [])->action(function () {
            $this->only = [];
            $this->resetTable();
        })];
    }

    private function list(): ?PriceList
    {
        $id = $this->tableFilters['list']['value'] ?? null;

        return RequestMemo::get('price-list:'.($id ?? 'default'), fn () => $id ? PriceList::find($id) : PriceList::where('is_default', true)->first());
    }

    /** Today's single-unit price per product unit on the list. */
    private function prices(): array
    {
        $list = $this->list();
        if (! $list) {
            return [];
        }

        return RequestMemo::get("price-list-prices:{$list->id}", function () use ($list) {
            $now = now();

            return Price::where('price_list_id', $list->id)->where('min_qty', '<=', 1)->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))->orderBy('min_qty')->orderByRaw('starts_at IS NOT NULL')->pluck('amount_minor', 'product_uom_id')->all();
        });
    }

    /** Stock and average cost per product, in the stores this person may see. */
    private function stock(): array
    {
        return RequestMemo::get('price-list-stock', function () {
            $branches = Erp::branchIds('catalog.view');

            return DB::table('stock_levels as l')->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')->when($branches !== null, fn ($q) => $q->whereIn('w.branch_id', $branches))
                ->groupBy('l.product_id')->selectRaw('l.product_id, SUM(l.on_hand) AS on_hand, SUM(l.on_hand * l.avg_cost_minor) / NULLIF(SUM(CASE WHEN l.avg_cost_minor IS NOT NULL THEN l.on_hand END), 0) AS cost')
                ->get()->keyBy('product_id')->all();
        });
    }

    /** @return array{net: int, tax: int, gross: int}|null the price, split, for one unit */
    private function amounts(ProductUom $u): ?array
    {
        $price = $this->prices()[$u->id] ?? null;
        if ($price === null) {
            return null;
        }
        $rates = $u->product->taxGroup?->rates->where('is_active', true)->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all() ?? [];

        return TaxCalculator::line((int) $price, $rates, (bool) $this->list()?->prices_include_tax);
    }

    private function cost(ProductUom $u): ?int
    {
        $avg = $this->stock()[$u->product_id]->cost ?? null;

        return $avg === null ? null : StockProjection::costPerUnit(number_format((float) $avg, 4, '.', ''), (string) $u->factor);
    }

    public function table(Table $table): Table
    {
        $money = fn (?int $v) => $v === null ? '—' : FinanceUi::money($v);
        $mayCost = fn () => Erp::canAnywhere('pricing.manage');

        return $table
            ->query(fn () => ProductUom::query()->join('products as p', 'p.id', '=', 'product_uoms.product_id')->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->select('product_uoms.*')->where('product_uoms.is_sellable', true)->with('uom', 'product.category', 'product.brand', 'product.taxGroup.rates')
                ->when($this->only, fn ($q) => $q->whereIn('product_uoms.product_id', $this->only))->orderBy('c.name')->orderBy('p.name')->orderBy('product_uoms.factor'))
            ->columns([
                TextColumn::make('product.sku')->label('SKU')->toggleable(),
                TextColumn::make('product.name')->label('Product')->searchable(),
                TextColumn::make('product.category.name')->label('Category')->placeholder('—')->toggleable(),
                TextColumn::make('product.brand.name')->label('Brand')->placeholder('—')->toggleable(),
                TextColumn::make('unit')->label('Unit')->state(fn (ProductUom $u) => $u->uom->code.((float) $u->factor != 1 ? ' × '.rtrim(rtrim((string) $u->factor, '0'), '.') : '')),
                TextColumn::make('price_net')->label('Before tax')->alignEnd()->toggleable()->state(fn (ProductUom $u) => $money($this->amounts($u)['net'] ?? null)),
                TextColumn::make('price_tax')->label('VAT and levies')->alignEnd()->toggleable()->state(fn (ProductUom $u) => $money($this->amounts($u)['tax'] ?? null)),
                TextColumn::make('price_gross')->label('Customer pays')->alignEnd()->weight('bold')->state(fn (ProductUom $u) => $money($this->amounts($u)['gross'] ?? null)),
                TextColumn::make('on_hand')->label('In stock')->alignEnd()->toggleable(isToggledHiddenByDefault: true)
                    ->state(fn (ProductUom $u) => $u->product->track_stock ? rtrim(rtrim(number_format((float) ($this->stock()[$u->product_id]->on_hand ?? 0) / (float) $u->factor, 3, '.', ''), '0'), '.') : '—'),
                TextColumn::make('cost')->label('Cost')->alignEnd()->visible($mayCost)->toggleable(isToggledHiddenByDefault: true)->state(fn (ProductUom $u) => $money($this->cost($u))),
                TextColumn::make('margin')->label('Margin')->alignEnd()->visible($mayCost)->toggleable(isToggledHiddenByDefault: true)->state(function (ProductUom $u) {
                    $net = $this->amounts($u)['net'] ?? null;
                    $cost = $this->cost($u);

                    return $net && $cost !== null ? round(($net - $cost) * 100 / $net).'%' : '—';
                }),
            ])
            ->filters([
                SelectFilter::make('list')->label('Price list')->selectablePlaceholder(false)->options(fn () => PriceList::where('is_active', true)->orderByDesc('is_default')->orderBy('name')->pluck('name', 'id'))
                    ->default(fn () => PriceList::where('is_default', true)->value('id'))->query(fn (Builder $query) => $query),
                SelectFilter::make('category')->label('Category')->multiple()->searchable()->options(fn () => Category::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['values'] ?? [], fn ($q, $v) => $q->whereIn('p.category_id', $v))),
                SelectFilter::make('brand')->label('Brand')->multiple()->searchable()->options(fn () => Brand::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['values'] ?? [], fn ($q, $v) => $q->whereIn('p.brand_id', $v))),
                SelectFilter::make('supplier')->label('Supplier')->multiple()->searchable()->options(fn () => Supplier::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['values'] ?? [], fn ($q, $v) => $q->whereIn('product_uoms.product_id', DB::table('supplier_products')->whereIn('supplier_id', $v)->select('product_id')))),
                SelectFilter::make('product')->label('Product')->multiple()->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Product::where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%"))->limit(30)->pluck('name', 'id'))
                    ->getOptionLabelsUsing(fn (array $values) => Product::whereIn('id', $values)->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['values'] ?? [], fn ($q, $v) => $q->whereIn('product_uoms.product_id', $v))),
                SelectFilter::make('active')->label('Status')->default('1')->options(['1' => 'Active products', '0' => 'Inactive products'])
                    ->query(fn (Builder $query, array $data) => $query->when(($data['value'] ?? '') !== '', fn ($q) => $q->where('p.is_active', $data['value'] === '1'))),
                Filter::make('in_stock')->label('In stock only')->query(fn (Builder $query) => $query->whereIn('product_uoms.product_id', DB::table('stock_levels')->where('on_hand', '>', 0)->select('product_id'))),
                Filter::make('priced')->label('With a price only')->query(fn (Builder $query) => $query->whereIn('product_uoms.id', array_keys($this->prices()))),
            ])
            ->emptyStateHeading('No products match');
    }
}
