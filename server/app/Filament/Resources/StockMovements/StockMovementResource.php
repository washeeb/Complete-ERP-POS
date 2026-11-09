<?php

namespace App\Filament\Resources\StockMovements;

use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Support\Erp;
use App\Filament\Support\IndustryScope;
use App\Filament\Support\ListFilters;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\Qty;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?string $navigationLabel = 'Stock ledger';

    protected static ?int $navigationSort = 2;

    /** What a movement can be, for the filter. */
    public const TYPES = ['sale' => 'Sale', 'sale_return' => 'Return from a customer', 'receipt' => 'Goods received', 'receipt_reversal' => 'Goods received, reversed', 'adjustment' => 'Adjustment',
        'count' => 'Stock count', 'opening' => 'Opening', 'transfer_out' => 'Transfer out', 'transfer_in' => 'Transfer in', 'supplier_return' => 'Return to supplier', 'delivery' => 'Delivery to customer',
        'customer_return' => 'Customer return', 'batch_assign' => 'Batch tracking started', 'unit_conversion' => 'Unit changed', 'cost_set' => 'Cost set by a cost run', 'cost_change' => 'Cost changed by a cost run',
        'stock_received' => 'Received with a cost'];

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('stock.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = StockLevelResource::warehouseIds();

        return IndustryScope::branches(parent::getEloquentQuery()->with('product', 'warehouse', 'uom')
            ->when($ids !== null, fn ($q) => $q->whereIn('warehouse_id', $ids)));
    }

    public static function table(Table $table): Table
    {
        $qty = fn ($state) => rtrim(rtrim(Qty::format(Qty::toMilli((string) $state)), '0'), '.');

        return $table
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('warehouse.code')->label('Warehouse'),
                TextColumn::make('product.name')->label('Product')->searchable()->wrap(),
                TextColumn::make('type')->badge(),
                TextColumn::make('qty_entered')->label('Entered')->formatStateUsing(fn ($state, StockMovement $r) => $qty($state).' '.$r->uom->code),
                TextColumn::make('qty_base')->label('Base qty')->formatStateUsing($qty)
                    ->color(fn ($state) => Qty::toMilli((string) $state) < 0 ? 'danger' : 'success'),
                TextColumn::make('reason')->wrap()->limit(60),
                TextColumn::make('actor_name')->asPerson('actor_id')->label('By'),
            ])
            ->filters([
                ListFilters::dates('occurred_at', 'Date'),
                SelectFilter::make('product_id')->label('Product')->multiple()->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Product::where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%"))->limit(30)->pluck('name', 'id'))
                    ->getOptionLabelsUsing(fn (array $values) => Product::whereIn('id', $values)->pluck('name', 'id')),
                SelectFilter::make('type')->multiple()->options(self::TYPES),
                SelectFilter::make('warehouse_id')->label('Warehouse')->multiple()->options(fn () => Warehouse::orderBy('code')->pluck('code', 'id')),
                SelectFilter::make('category')->label('Category')->multiple()->searchable()->options(fn () => Category::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['values'] ?? [], fn ($q, $v) => $q->whereIn('product_id', Product::whereIn('category_id', $v)->select('id')))),
                SelectFilter::make('brand')->label('Brand')->multiple()->searchable()->options(fn () => Brand::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['values'] ?? [], fn ($q, $v) => $q->whereIn('product_id', Product::whereIn('brand_id', $v)->select('id')))),
                ListFilters::person(self::class, 'actor_id', 'actor_name', 'By'),
            ])
            ->defaultSort('occurred_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListStockMovements::route('/')];
    }
}
