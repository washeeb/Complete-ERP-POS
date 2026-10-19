<?php

namespace App\Filament\Resources\StockLevels;

use App\Filament\Resources\StockLevels\Pages\ListStockLevels;
use App\Filament\Support\Erp;
use App\Models\StockLevel;
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

class StockLevelResource extends Resource
{
    protected static ?string $model = StockLevel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?string $navigationLabel = 'Stock on hand';

    protected static ?string $modelLabel = 'stock level';

    protected static ?int $navigationSort = 1;

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

    /** @return list<string>|null warehouse ids the viewer may see (null = all) */
    public static function warehouseIds(string $permission = 'stock.view'): ?array
    {
        $branches = Erp::branchIds($permission);

        return $branches === null ? null : Warehouse::whereIn('branch_id', $branches)->pluck('id')->all();
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = self::warehouseIds();

        return parent::getEloquentQuery()->with('product.baseUom', 'warehouse')
            ->when($ids !== null, fn ($q) => $q->whereIn('warehouse_id', $ids));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('warehouse.code')->label('Warehouse')->sortable(),
                TextColumn::make('product.sku')->label('SKU')->searchable(),
                TextColumn::make('product.name')->label('Product')->searchable()->wrap(),
                TextColumn::make('on_hand')->label('On hand')->sortable()
                    ->formatStateUsing(fn ($state, StockLevel $r) => rtrim(rtrim(Qty::format(Qty::toMilli((string) $state)), '0'), '.').' '.$r->product->baseUom->code)
                    ->color(fn ($state) => Qty::toMilli((string) $state) < 0 ? 'danger' : null),
                TextColumn::make('updated_at')->label('Last movement')->since(),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')->label('Warehouse')
                    ->options(fn () => Warehouse::when(self::warehouseIds() !== null, fn ($q) => $q->whereIn('id', self::warehouseIds()))
                        ->orderBy('code')->get()->mapWithKeys(fn ($w) => [$w->id => "{$w->code} · {$w->name}"])),
                SelectFilter::make('level')->label('Stock')->options(['in' => 'In stock', 'out' => 'Out of stock (0)', 'negative' => 'Negative (investigate)'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'in' => $query->where('on_hand', '>', 0), 'out' => $query->where('on_hand', 0), 'negative' => $query->where('on_hand', '<', 0), default => $query
                    }),
            ])
            ->defaultSort('warehouse_id');
    }

    public static function getPages(): array
    {
        return ['index' => ListStockLevels::route('/')];
    }
}
