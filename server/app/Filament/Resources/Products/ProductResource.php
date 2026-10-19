<?php

namespace App\Filament\Resources\Products;

use App\Domain\Catalog\CatalogService;
use App\Domain\Modules\ModuleService;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Support\Erp;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Industry;
use App\Models\KitchenStation;
use App\Models\ModifierGroup;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Supplier;
use App\Models\TaxGroup;
use App\Models\Uom;
use App\Support\IndustryContext;
use App\Support\Money;
use App\Support\Qty;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
                TextInput::make('sku')->label('SKU')->disabled()->dehydrated(false)->placeholder('Given automatically when saved')
                    ->helperText('Set by the system so no two products share one; it never changes.'),
                TextInput::make('name')->required()->maxLength(160)->columnSpan(2),
                Select::make('industry_id')->label('Industry')->options(fn () => Industry::licensed()->orderBy('sort')->pluck('name', 'id'))
                    ->default(fn () => IndustryContext::selected())->placeholder('All industries (shared)')
                    ->visible(fn () => Industry::exists())
                    ->helperText('Each industry has its own catalogue: its tills and screens show only its products. Shared items are sold by every industry.'),
                TextInput::make('location')->label('Location / shelf')->maxLength(60)->placeholder('e.g. Shelf B2')
                    ->helperText('Where it is kept: shows on the stock and count sheets and in the till\'s search.'),
                Select::make('category_id')->label('Category')->options(fn () => Category::where('is_active', true)->orderBy('name')->pluck('name', 'id'))->searchable(),
                Select::make('brand_id')->label('Brand')->options(fn () => Brand::orderBy('name')->pluck('name', 'id'))->searchable(),
                Select::make('tax_group_id')->label('Tax')->required()
                    ->options(fn () => TaxGroup::orderBy('name')->pluck('name', 'id'))
                    ->default(fn () => TaxGroup::where('code', 'STD')->value('id')),
                Select::make('base_uom_id')->label('Base unit (stock is kept in this)')->required()->options($uoms)
                    ->default(fn () => Uom::where('code', 'PC')->value('id'))
                    ->disabled(fn (?Product $record) => $record?->stockLevels()->exists())
                    ->helperText(fn (?Product $record) => $record?->stockLevels()->exists() ? 'Stock has moved: use Correct the unit (top of the page) if it is wrong.' : null),
                Toggle::make('track_stock')->label('Track stock')->default(true)->inline(false),
                Toggle::make('is_active')->label('For sale')->default(true)->inline(false),
                Toggle::make('track_batches')->label('Batches and expiry dates')->inline(false)
                    ->helperText('Received with batch number and expiry; sold oldest expiry first; expired stock cannot be sold.'),
                TextInput::make('plu')->label('Scale code (PLU)')->maxLength(5)->regex('/^\d{1,5}$/')
                    ->helperText('For items weighed on the label scale (Grocery): the item number the scale prints in the barcode.'),
                Textarea::make('description')->rows(2)->columnSpanFull(),
            ]),
            Section::make('Medicine')->columns(3)->visible(fn () => ModuleService::enabledAnywhere('pharmacy'))
                ->description('Pharmacy: prescription-only and controlled medicines need a pharmacist’s approval at the till. Medicines must track batches and expiry.')
                ->schema([
                    Select::make('drug_schedule')->label('Schedule')->options(Product::SCHEDULES)->placeholder('Not a medicine'),
                    TextInput::make('generic_name')->label('Generic name')->maxLength(255),
                    TextInput::make('strength')->maxLength(60)->placeholder('500 mg'),
                    TextInput::make('dosage_form')->label('Form')->maxLength(40)->placeholder('Capsule'),
                    TextInput::make('fda_reg_no')->label('FDA registration no.')->maxLength(60),
                    TagsInput::make('active_ingredients')->label('Active ingredients')->placeholder('Type, then Enter')
                        ->helperText('Used for allergy and interaction warnings.'),
                ]),
            Section::make('Serial numbers and warranty')->columns(2)->visible(fn () => ModuleService::enabledAnywhere('electronics'))
                ->description('Electronics: each unit is received, transferred and sold by its serial / IMEI number; the warranty runs from the date sold.')
                ->schema([
                    Toggle::make('track_serials')->label('Serial / IMEI numbers')->inline(false)
                        ->helperText('Needs "Track stock"; not with batches. Stock already on hand gets its numbers under Electronics → Serial numbers.'),
                    TextInput::make('warranty_months')->label('Warranty (months)')->numeric()->minValue(0)->maxValue(120),
                ]),
            Section::make('Returnable containers (empties)')->columns(3)->visible(fn () => ModuleService::enabledAnywhere('distribution'))
                ->description('Crates, bottles, gas cylinders: the container is a product of its own, sold at its deposit and bought back at the same price when the empty comes back.')
                ->schema([
                    Toggle::make('is_container')->label('This product is a returnable container')->inline(false)
                        ->helperText('Its price is the deposit. Empties handed back at the till come in as a minus quantity.'),
                    Select::make('deposit_product_id')->label('Sold with this container (deposit)')->placeholder('No deposit')->searchable()
                        ->options(fn () => Product::where('is_container', true)->orderBy('name')->pluck('name', 'id')),
                    TextInput::make('deposit_per_unit')->label('Containers per unit sold')->numeric()->minValue(0.001)->default('1')
                        ->helperText('1 for a bottle; 0.042 if a crate holds 24 and you sell single bottles.'),
                ]),
            Section::make('Consignment')->columns(2)->visible(fn () => ModuleService::enabledAnywhere('fashion'))
                ->description('Stock that belongs to a supplier: received at no cost (Stock → adjustment "Consignment received"); each sale owes them their share, settled from Fashion → Sell-through & consignment.')
                ->schema([
                    Select::make('consignor_id')->label('Consignor (supplier)')->placeholder('Our own stock')->searchable()
                        ->options(fn () => Supplier::orderBy('name')->pluck('name', 'id')),
                    TextInput::make('consignment_bp')->label('Their share (basis points of the price before tax)')->numeric()->minValue(1)->maxValue(10000)
                        ->helperText('6000 = 60 %.'),
                ]),
            Section::make('Services')->columns(3)->visible(fn () => ModuleService::enabledAnywhere('services'))
                ->description('A bookable service has a duration (it appears in the appointment book). A prepaid pack is sold once and gives the customer sessions, used later at the till at no charge. Packs and licence-key products do not track stock.')
                ->schema([
                    TextInput::make('service_minutes')->label('Duration (minutes)')->numeric()->minValue(5)->maxValue(720)
                        ->helperText('Fill in for a service that is booked, e.g. 45 for a haircut.'),
                    TextInput::make('commission_bp')->label('Commission (basis points)')->numeric()->minValue(0)->maxValue(10000)
                        ->helperText('Empty = the staff member’s own rate. 1000 = 10 % of the price before tax.'),
                    Toggle::make('licence_keys')->label('Hands out a licence key')->inline(false)
                        ->helperText('Software: one key per unit sold, from the keys loaded under Services → Licence keys.'),
                    Repeater::make('pack.items')->label('Prepaid pack: what it includes')->defaultItems(0)->columns(3)->reorderable(false)->columnSpanFull()
                        ->helperText('Leave empty if this is not a pack. Example: "10 massages" = one row with the massage services and 10 sessions.')
                        ->schema([
                            Select::make('product_ids')->label('Used for (services)')->multiple()->required()->searchable()
                                ->options(fn () => Product::where('is_active', true)->whereNotNull('service_minutes')->orderBy('name')->pluck('name', 'id')),
                            TextInput::make('sessions')->label('Sessions')->required()->numeric()->minValue(1)->maxValue(1000),
                            TextInput::make('label')->label('Shown as (optional)')->maxLength(120),
                        ]),
                    TextInput::make('pack.valid_days')->label('Pack valid for (days)')->numeric()->minValue(1)->maxValue(3650)
                        ->helperText('Empty = no expiry.'),
                ]),
            Section::make('Restaurant')->columns(2)->visible(fn () => ModuleService::enabledAnywhere('restaurant'))
                ->description('Dishes and drinks: where they are prepared, the choices offered, and the ingredients one portion uses (taken from stock when the bill is paid; switch "Track stock" off for the dish itself).')
                ->schema([
                    Select::make('station')->label('Prepared at (kitchen station)')->placeholder('Not sent to a kitchen')
                        ->options(fn () => KitchenStation::where('is_active', true)->orderBy('code')->get()->mapWithKeys(fn ($s) => [$s->code => "{$s->code} · {$s->name}"])->all()),
                    Select::make('modifier_group_ids')->label('Choices offered')->multiple()
                        ->options(fn () => ModifierGroup::where('is_active', true)->orderBy('name')->pluck('name', 'id')),
                    Repeater::make('recipe')->label('Recipe (per one)')->defaultItems(0)->columns(2)->reorderable(false)->columnSpanFull()
                        ->schema([
                            Select::make('product_uom_id')->label('Ingredient')->required()->searchable()
                                ->getSearchResultsUsing(fn (string $search) => self::ingredientOptions($search))
                                ->getOptionLabelUsing(fn ($value) => self::ingredientLabel($value)),
                            TextInput::make('qty')->label('Quantity')->required()->numeric()->minValue(0.001),
                        ]),
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

    /** Ingredients for recipes: stocked products, by unit. */
    public static function ingredientOptions(string $search): array
    {
        return ProductUom::with(['uom', 'product'])->whereHas('product', fn ($q) => $q->where('track_stock', true)->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%")))
            ->limit(30)->get()->mapWithKeys(fn ($u) => [$u->id => "{$u->product->name} ({$u->uom->code})"])->all();
    }

    public static function ingredientLabel(?string $id): ?string
    {
        $u = $id ? ProductUom::with(['uom', 'product'])->find($id) : null;

        return $u ? "{$u->product->name} ({$u->uom->code})" : null;
    }

    /** Model → form arrays (units/barcodes/prices) for editing. */
    public static function toFormData(Product $p): array
    {
        return CatalogService::inputOf($p) + [
            'recipe' => $p->recipe()->get()->map(fn ($r) => ['product_uom_id' => $r->ingredient_uom_id, 'qty' => rtrim(rtrim((string) $r->qty, '0'), '.')])->all(),
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
                TextColumn::make('location')->label('Location')->placeholder('—')->toggleable()->searchable(),
                TextColumn::make('drug_schedule')->label('Schedule')->badge()->placeholder('—')->toggleable()
                    ->formatStateUsing(fn ($state) => ['otc' => 'OTC', 'pom' => 'POM', 'controlled' => 'Controlled'][$state] ?? $state)
                    ->color(fn ($state) => ['controlled' => 'danger', 'pom' => 'warning'][$state] ?? 'gray')
                    ->visible(fn () => ModuleService::enabledAnywhere('pharmacy')),
                TextColumn::make('industry.name')->label('Industry')->placeholder('Shared')->toggleable()->visible(fn () => Industry::exists()),
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with('category', 'industry', 'baseUom', 'units', 'prices', 'stockLevels')
                // The industry chosen at the top: its own products and the shared ones.
                ->when(IndustryContext::selected(), fn ($q, $id) => $q->where(fn ($w) => $w->whereNull('industry_id')->orWhere('industry_id', $id))))
            ->filters([
                SelectFilter::make('category_id')->label('Category')->options(fn () => Category::orderBy('name')->pluck('name', 'id')),
                TernaryFilter::make('is_active')->label('For sale'),
                SelectFilter::make('drug_schedule')->label('Medicine schedule')->options(Product::SCHEDULES + ['none' => 'Not a medicine'])
                    ->query(fn (Builder $q, array $data) => match ($data['value'] ?? null) {
                        null, '' => $q, 'none' => $q->whereNull('drug_schedule'), default => $q->where('drug_schedule', $data['value'])
                    })
                    ->visible(fn () => ModuleService::enabledAnywhere('pharmacy')),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkAction::make('schedule')->label('Set medicine schedule')->icon(Heroicon::OutlinedShieldCheck)
                    ->visible(fn () => ModuleService::enabledAnywhere('pharmacy') && Erp::can('catalog.manage'))
                    ->modalDescription('Prescription-only and controlled medicines need a pharmacist\'s approval at the till; controlled drugs go in the controlled-drug register. Medicines are switched to batches and expiry dates.')
                    ->schema([Select::make('drug_schedule')->label('Schedule for the ticked products')->required()
                        ->options(Product::SCHEDULES + ['none' => 'Not a medicine'])])
                    ->action(function (Collection $records, array $data) {
                        @set_time_limit(1800);
                        $schedule = $data['drug_schedule'] === 'none' ? null : $data['drug_schedule'];
                        $n = Erp::run(fn ($actor) => DB::transaction(function () use ($actor, $records, $schedule) {
                            foreach ($records as $p) {
                                app(CatalogService::class)->saveProduct($actor, $p, ['drug_schedule' => $schedule] + ($schedule && ! $p->track_batches ? ['track_batches' => true] : []),
                                    'Medicine schedule set from the products list');
                            }

                            return $records->count();
                        }));
                        Notification::make()->success()->title("{$n} product(s) updated")->send();
                    })
                    ->deselectRecordsAfterCompletion(),
                // Products in the wrong industry's catalogue (e.g. imported before the import asked): move the ticked ones.
                BulkAction::make('moveIndustry')->label('Move to industry')->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->visible(fn () => Industry::licensed()->exists() && Erp::can('catalog.manage'))
                    ->schema([Select::make('industry')->label('Move the ticked products to')->required()
                        ->options(fn () => Industry::licensed()->orderBy('sort')->pluck('name', 'id')->all() + ['shared' => 'Shared by all industries'])])
                    ->action(function (Collection $records, array $data) {
                        @set_time_limit(1800);
                        $n = Erp::run(fn ($actor) => app(CatalogService::class)->moveToIndustry($actor, $records, $data['industry'] === 'shared' ? null : $data['industry']));
                        Notification::make()->success()->title("{$n} product(s) moved")->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
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
