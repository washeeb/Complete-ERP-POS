<?php

namespace App\Filament\Resources\ReorderRules;

use App\Domain\Purchasing\ReorderLevels;
use App\Domain\Purchasing\ReorderService;
use App\Filament\Resources\ReorderRules\Pages\ListReorderRules;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\ReorderRule;
use App\Models\Warehouse;
use App\Support\Qty;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class ReorderRuleResource extends Resource
{
    protected static ?string $model = ReorderRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static string|UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?string $navigationLabel = 'Reorder levels';

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('warehouse.manage') || Erp::canAnywhere('purchasing.manage');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('warehouse.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return Erp::can('warehouse.manage', $record->warehouse);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = Erp::branchIds('stock.view');

        return parent::getEloquentQuery()->with('product', 'warehouse')->where('source', '<>', 'default') // rows back on the default are history only
            ->when($ids !== null, fn ($q) => $q->whereIn('warehouse_id', Warehouse::whereIn('branch_id', $ids)->pluck('id')));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('warehouse_id')->label('Store')->required()->disabledOn('edit')
                ->options(fn () => ['all' => 'Every store'] + Fields::warehouses('warehouse.manage')),
            Select::make('product_id')->label('Product')->required()->disabledOn('edit')->searchable()->live()
                ->getSearchResultsUsing(fn (string $search) => Product::where('track_stock', true)->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('sku', 'ilike', "%{$search}%"))->limit(30)->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->sku} · {$p->name}"]))
                ->getOptionLabelUsing(fn ($value) => ($p = Product::find($value)) ? "{$p->sku} · {$p->name}" : null)
                ->afterStateUpdated(fn ($state, Set $set) => $set('product_uom_id', $state ? self::buyingUnitId($state) : null)),
            Select::make('product_uom_id')->label('Unit')->required()
                ->options(fn (Get $get) => ProductUom::with('uom')->where('product_id', $get('product_id'))->orderBy('factor')->get()
                    ->mapWithKeys(fn ($u) => [$u->id => $u->uom->code.($u->is_base ? '' : ' of '.Fields::qty((string) $u->factor))])->all())
                ->helperText('The unit the figures below are in (the buying unit is chosen for you).'),
            TextInput::make('min')->label('Minimum')->numeric()->minValue(0)->required()->helperText('Reorder when stock plus what is on order falls to this.'),
            TextInput::make('max')->label('Maximum (order up to)')->numeric()->minValue(0.001)->required()->helperText('Top up to this level.'),
        ]);
    }

    private static function buyingUnitId(string $productId): ?string
    {
        $factor = ReorderLevels::buyingUnit($productId)['factor'];

        return ProductUom::where('product_id', $productId)->get()->sortBy(fn ($u) => abs(Qty::factorMicro((string) $u->factor) - $factor))->first()?->id;
    }

    /** Figures typed in a unit → base units, for one store or every store. */
    private static function save(array $data, ?ReorderRule $rule = null): Model
    {
        $unit = ProductUom::findOrFail($data['product_uom_id']);
        $factor = Qty::factorMicro((string) $unit->factor);
        $base = fn ($v) => Qty::format((int) round(Qty::toMilli((string) $v) * $factor / 1_000_000));
        $product = Product::findOrFail($data['product_id'] ?? $rule->product_id);
        $warehouses = ($data['warehouse_id'] ?? $rule->warehouse_id) === 'all'
            ? Warehouse::whereIn('id', array_keys(Fields::warehouses('warehouse.manage')))->get()
            : collect([Warehouse::findOrFail($data['warehouse_id'] ?? $rule->warehouse_id)]);

        return Erp::run(fn ($actor) => DB::transaction(fn () => $warehouses->map(fn ($w) => app(ReorderService::class)
            ->saveRule($actor, $w, $product, $base($data['min']), $base($data['max'])))->last()), 'Saved');
    }

    /** A level in base units shown in the product's buying unit, with the base figure after it. */
    public static function inBuyingUnit(string $productId, $state): string
    {
        $u = ReorderLevels::buyingUnit($productId);
        if ($u['factor'] === 1_000_000) {
            return Fields::qty((string) $state);
        }

        return Fields::qty(Qty::format((int) round(Qty::toMilli((string) $state) * 1_000_000 / $u['factor'])), $u['code']).' ('.Fields::qty((string) $state).')';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description(fn () => 'Every stock product without its own figure follows the company default (System → Settings → Stock: '
                .app(Settings::class)->get('stock.default_min_qty', Erp::user()?->tenant_id).' of its buying unit, ordered up to twice that). Figures here replace it in one store.')
            ->columns([
                TextColumn::make('product.name')->label('Product')->searchable()->description(fn (ReorderRule $record) => $record->product->sku),
                TextColumn::make('warehouse.code')->label('Store'),
                TextColumn::make('on_hand')->label('On hand')->state(fn (ReorderRule $record) => Fields::qty(DB::table('stock_levels')
                    ->where('warehouse_id', $record->warehouse_id)->where('product_id', $record->product_id)->value('on_hand'))),
                TextColumn::make('min_qty')->label('Min')->formatStateUsing(fn ($state, ReorderRule $record) => self::inBuyingUnit($record->product_id, $state)),
                TextColumn::make('max_qty')->label('Max')->formatStateUsing(fn ($state, ReorderRule $record) => self::inBuyingUnit($record->product_id, $state)),
                TextColumn::make('source')->label('Set by')->badge()->formatStateUsing(fn ($state) => ReorderLevels::SOURCES[$state] ?? $state)
                    ->color(fn ($state) => $state === 'smart' ? 'info' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')->label('Store')->options(fn () => Warehouse::orderBy('code')->pluck('code', 'id')),
                SelectFilter::make('set_by')->attribute('source')->label('Set by')->options(['own' => 'Own figure', 'smart' => 'Smart level']),
            ])
            ->pushHeaderActions([CreateAction::make()->label('Own figure for a product')->using(fn (array $data): Model => self::save($data))])
            ->recordActions([
                EditAction::make()->mutateRecordDataUsing(fn (array $data, ReorderRule $record) => ['product_uom_id' => ProductUom::where('product_id', $record->product_id)
                    ->where('is_base', true)->value('id'), 'min' => (float) $record->min_qty, 'max' => (float) $record->max_qty] + $data)
                    ->using(fn (ReorderRule $record, array $data): Model => self::save(['product_id' => $record->product_id, 'warehouse_id' => $record->warehouse_id] + $data, $record)),
                Action::make('useDefault')->label('Use the default')->color('gray')->requiresConfirmation()
                    ->visible(fn (ReorderRule $record) => Erp::can('warehouse.manage', $record->warehouse))
                    ->action(fn (ReorderRule $record) => Erp::run(fn ($actor) => app(ReorderService::class)->useDefault($actor, $record), 'Back to the default')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListReorderRules::route('/')];
    }
}
