<?php

namespace App\Filament\Resources\StockMovements;

use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Support\Erp;
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

        return parent::getEloquentQuery()->with('product', 'warehouse', 'uom')
            ->when($ids !== null, fn ($q) => $q->whereIn('warehouse_id', $ids));
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
                TextColumn::make('actor_name')->label('By'),
            ])
            ->filters([
                SelectFilter::make('type')->options(['opening' => 'Opening', 'adjustment' => 'Adjustment', 'sale' => 'Sale']),
                SelectFilter::make('warehouse_id')->label('Warehouse')->options(fn () => Warehouse::orderBy('code')->pluck('code', 'id')),
            ])
            ->defaultSort('occurred_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListStockMovements::route('/')];
    }
}
