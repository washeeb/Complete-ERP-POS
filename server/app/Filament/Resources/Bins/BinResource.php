<?php

namespace App\Filament\Resources\Bins;

use App\Domain\Inventory\BinService;
use App\Filament\Resources\Bins\Pages\ListBins;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\Bin;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/** Shelf locations in bin-managed warehouses, what is in them, and moving stock between them. */
class BinResource extends Resource
{
    protected static ?string $model = Bin::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?string $navigationLabel = 'Bins';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('stock.view');
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
        return false; // switch off instead: movements refer to bins
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = Erp::branchIds('stock.view');

        return parent::getEloquentQuery()->with('warehouse')
            ->when($ids !== null, fn ($q) => $q->whereIn('warehouse_id', Warehouse::whereIn('branch_id', $ids)->pluck('id')));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('warehouse_id')->label('Warehouse')->required()->disabledOn('edit')
                ->options(fn () => Warehouse::where('use_bins', true)->orderBy('code')->get()->mapWithKeys(fn ($w) => [$w->id => "{$w->code} · {$w->name}"]))
                ->helperText('Only warehouses with bins switched on are listed.'),
            TextInput::make('code')->required()->maxLength(30)->helperText('Aisle-bay-level, e.g. A-01-02. Print it as a barcode on the shelf.'),
            TextInput::make('name')->maxLength(255),
            TextInput::make('pick_order')->label('Pick order')->numeric()->minValue(0)->default(100)
                ->helperText('Sales and transfers take stock from lower numbers first.'),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        $save = fn (Bin $bin, array $data) => Erp::run(fn ($actor) => app(BinService::class)->saveBin($actor, $bin, $data), 'Saved');

        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable()->description(fn (Bin $record) => $record->name),
                TextColumn::make('warehouse.code')->label('Warehouse'),
                TextColumn::make('kind')->badge()->formatStateUsing(fn ($state) => Bin::KINDS[$state] ?? $state),
                TextColumn::make('pick_order')->label('Pick order')->sortable(),
                TextColumn::make('contents')->label('Holds')->state(fn (Bin $record) => DB::table('stock_bin_levels')->join('products', 'products.id', '=', 'stock_bin_levels.product_id')
                    ->where('bin_id', $record->id)->where('on_hand', '<>', 0)->orderBy('products.name')->limit(4)->get(['products.name', 'on_hand'])
                    ->map(fn ($r) => Fields::qty($r->on_hand).' '.$r->name)->implode(', ') ?: 'empty')->wrap(),
                IconColumn::make('is_active')->boolean()->label('Active'),
            ])
            ->filters([SelectFilter::make('warehouse_id')->label('Warehouse')->options(fn () => Warehouse::where('use_bins', true)->pluck('code', 'id'))])
            ->defaultSort('pick_order')
            ->pushHeaderActions([
                CreateAction::make()->using(fn (array $data): Model => $save(new Bin, $data)),
            ])
            ->recordActions([
                Action::make('move')->label('Move stock')->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->visible(fn (Bin $record) => Erp::can('stock.receive', $record->warehouse) || Erp::can('stock.transfer', $record->warehouse))
                    ->schema(fn (Bin $record) => [
                        Select::make('product_id')->label('Product')->required()->live()
                            ->options(DB::table('stock_bin_levels')->join('products', 'products.id', '=', 'stock_bin_levels.product_id')
                                ->where('bin_id', $record->id)->where('on_hand', '>', 0)->orderBy('products.name')->pluck('products.name', 'products.id')),
                        Select::make('product_uom_id')->label('Unit')->required()
                            ->options(fn ($get) => ProductUom::with('uom')->where('product_id', $get('product_id'))->get()->mapWithKeys(fn ($u) => [$u->id => $u->uom->code])),
                        TextInput::make('qty')->label('Quantity')->numeric()->minValue(0.001)->required(),
                        Select::make('to_bin_id')->label('To bin')->required()->options(collect(Fields::bins($record->warehouse_id))->except($record->id)),
                    ])
                    ->action(fn (Bin $record, array $data) => Erp::run(fn ($actor) => app(BinService::class)->putAway($actor, $record->warehouse,
                        Product::findOrFail($data['product_id']), ProductUom::findOrFail($data['product_uom_id']), (string) $data['qty'], $record->id, $data['to_bin_id']), 'Moved')),
                EditAction::make()->using(fn (Bin $record, array $data): Model => $save($record, $data)),
            ])
            ->emptyStateHeading('No bins')
            ->emptyStateDescription('Switch bins on for a warehouse with “Bins on/off”; its receiving bin is created for you.');
    }

    public static function getPages(): array
    {
        return ['index' => ListBins::route('/')];
    }
}
