<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Support\Erp;
use App\Models\Brand;
use App\Models\Category;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\TaxGroup;
use App\Models\Uom;
use App\Support\Money;
use App\Support\Qty;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return Erp::can('catalog.view');
    }

    public static function canCreate(): bool
    {
        return Erp::can('catalog.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return Erp::can('catalog.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return false; // sales and stock history reference products; deactivate instead
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['sku', 'name'];
    }

    public static function form(Schema $schema): Schema
    {
        $uoms = fn () => Uom::orderBy('code')->get()->mapWithKeys(fn ($u) => [$u->id => "{$u->code} · {$u->name}"]);

        return $schema->columns(1)->components([
            Section::make('Product')->columns(3)->schema([
                TextInput::make('sku')->label('SKU')->required()->maxLength(40)->regex('/^[A-Za-z0-9][A-Za-z0-9._-]*$/'),
                TextInput::make('name')->required()->maxLength(160)->columnSpan(2),
                Select::make('category_id')->label('Category')->options(fn () => Category::where('is_active', true)->orderBy('name')->pluck('name', 'id'))->searchable(),
                Select::make('brand_id')->label('Brand')->options(fn () => Brand::orderBy('name')->pluck('name', 'id'))->searchable(),
                Select::make('tax_group_id')->label('Tax')->required()
                    ->options(fn () => TaxGroup::orderBy('name')->pluck('name', 'id'))
                    ->default(fn () => TaxGroup::where('code', 'STD')->value('id')),
                Select::make('base_uom_id')->label('Base unit (stock is kept in this)')->required()->options($uoms)
                    ->default(fn () => Uom::where('code', 'PC')->value('id'))
                    ->disabled(fn (?Product $record) => $record?->stockLevels()->exists())
                    ->helperText(fn (?Product $record) => $record?->stockLevels()->exists() ? 'Fixed once stock has moved.' : null),
                Toggle::make('track_stock')->label('Track stock')->default(true)->inline(false),
                Toggle::make('is_active')->label('For sale')->default(true)->inline(false),
                Textarea::make('description')->rows(2)->columnSpanFull(),
            ]),
            Section::make('Barcodes and selling units')->description('Scanning a barcode adds that unit to the till. Buy in cartons, sell in pieces: add the carton with how many base units it holds.')
                ->schema([
                    TagsInput::make('base_barcodes')->label('Barcodes for the base unit')->placeholder('Scan or type, then Enter'),
                    Repeater::make('units')->label('Other units')->defaultItems(0)->columns(5)->reorderable(false)
                        ->schema([
                            Select::make('uom_id')->label('Unit')->required()->options($uoms),
                            TextInput::make('factor')->label('Base units in one')->required()->numeric()->minValue(0.000001),
                            Toggle::make('is_sellable')->label('Sold')->default(true)->inline(false),
                            Toggle::make('is_purchasable')->label('Bought')->default(true)->inline(false),
                            TagsInput::make('barcodes')->label('Barcodes'),
                        ]),
                ]),
            Section::make('Prices')->visible(fn () => Erp::can('pricing.manage'))
                ->description('One row per price list and unit. Add rows with a minimum quantity for bulk prices, or with dates for a promotion.')
                ->schema([
                    Repeater::make('prices')->hiddenLabel()->defaultItems(0)->columns(6)->reorderable(false)
                        ->schema([
                            Select::make('price_list_id')->label('List')->required()
                                ->options(fn () => PriceList::where('is_active', true)->orderByDesc('is_default')->pluck('name', 'id'))
                                ->default(fn () => PriceList::where('is_default', true)->value('id')),
                            Select::make('uom_id')->label('Unit')->required()->options($uoms)->default(fn () => Uom::where('code', 'PC')->value('id')),
                            TextInput::make('min_qty')->label('From qty')->required()->numeric()->default('1'),
                            TextInput::make('amount')->label('Price')->required()->numeric()->minValue(0)->prefix(fn () => Erp::user()?->tenant->currency_code),
                            DateTimePicker::make('starts_at')->label('From')->seconds(false),
                            DateTimePicker::make('ends_at')->label('Until')->seconds(false),
                        ]),
                ]),
            Section::make('Reason')->visibleOn('edit')->schema([
                TextInput::make('reason')->label('Reason for change (optional, kept in the audit log)')->maxLength(255),
            ]),
        ]);
    }

    /** Model → form arrays (units/barcodes/prices) for editing. */
    public static function toFormData(Product $p): array
    {
        $p->load('units.uom', 'units.barcodes', 'prices.priceList', 'prices.productUom');
        $base = $p->units->firstWhere('is_base', true);

        return [
            'base_barcodes' => $base?->barcodes->pluck('code')->all() ?? [],
            'units' => $p->units->where('is_base', false)->where('is_sellable', true)->values()->map(fn ($u) => [
                'uom_id' => $u->uom_id, 'factor' => rtrim(rtrim((string) $u->factor, '0'), '.'), 'is_sellable' => $u->is_sellable,
                'is_purchasable' => $u->is_purchasable, 'barcodes' => $u->barcodes->pluck('code')->all(),
            ])->all(),
            'prices' => $p->prices->map(fn ($x) => [
                'price_list_id' => $x->price_list_id, 'uom_id' => $x->productUom->uom_id,
                'min_qty' => rtrim(rtrim((string) $x->min_qty, '0'), '.'), 'amount' => Money::format($x->amount_minor, $x->priceList->currency_code),
                'starts_at' => $x->starts_at?->toDateTimeString(), 'ends_at' => $x->ends_at?->toDateTimeString(),
            ])->all(),
        ];
    }

    public static function table(Table $table): Table
    {
        $default = PriceList::where('is_default', true)->first();

        return $table
            ->columns([
                // Search matches SKU, name, or an exact barcode (scan into the search box).
                TextColumn::make('sku')->label('SKU')->sortable()->searchable(query: fn (Builder $query, string $search) => $query
                    ->where(fn ($q) => $q->where('sku', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%")
                        ->orWhereHas('barcodes', fn ($b) => $b->where('code', trim($search))))),
                TextColumn::make('name')->sortable()->wrap(),
                TextColumn::make('category.name')->label('Category')->toggleable(),
                TextColumn::make('price')->label($default ? $default->name.' price' : 'Price')
                    ->state(function (Product $r) use ($default) {
                        $p = $r->prices->first(fn ($x) => $x->price_list_id === $default?->id && $x->product_uom_id === $r->units->firstWhere('is_base', true)?->id
                            && Qty::toMilli((string) $x->min_qty) === 1000 && ! $x->starts_at);

                        return $p ? Money::display($p->amount_minor, $default->currency_code) : '—';
                    }),
                TextColumn::make('stock')->label('On hand (all)')
                    ->state(fn (Product $r) => $r->track_stock ? rtrim(rtrim(Qty::format((int) $r->stockLevels->sum(fn ($l) => Qty::toMilli((string) $l->on_hand))), '0'), '.').' '.$r->baseUom->code : 'not tracked'),
                IconColumn::make('is_active')->boolean()->label('For sale'),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with('category', 'baseUom', 'units', 'prices', 'stockLevels'))
            ->filters([
                SelectFilter::make('category_id')->label('Category')->options(fn () => Category::orderBy('name')->pluck('name', 'id')),
                TernaryFilter::make('is_active')->label('For sale'),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
