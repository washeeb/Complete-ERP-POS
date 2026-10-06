<?php

namespace App\Filament\Resources\ReorderRules;

use App\Domain\Purchasing\ReorderService;
use App\Filament\Resources\ReorderRules\Pages\ListReorderRules;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\Product;
use App\Models\ReorderRule;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
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

        return parent::getEloquentQuery()->with('product', 'warehouse')
            ->when($ids !== null, fn ($q) => $q->whereIn('warehouse_id', Warehouse::whereIn('branch_id', $ids)->pluck('id')));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('warehouse_id')->label('Warehouse')->required()->disabledOn('edit')->options(fn () => Fields::warehouses('warehouse.manage')),
            Select::make('product_id')->label('Product')->required()->disabledOn('edit')->searchable()
                ->getSearchResultsUsing(fn (string $search) => Product::where('track_stock', true)->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('sku', 'ilike', "%{$search}%"))->limit(30)->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->sku} · {$p->name}"]))
                ->getOptionLabelUsing(fn ($value) => Product::find($value)?->name),
            TextInput::make('min_qty')->label('Minimum (base unit)')->numeric()->minValue(0)->required()->helperText('Reorder when stock plus what is on order falls below this.'),
            TextInput::make('max_qty')->label('Maximum (base unit)')->numeric()->minValue(0.001)->required()->helperText('Top up to this level.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $save = fn (array $data, ?ReorderRule $rule = null) => Erp::run(fn ($actor) => app(ReorderService::class)->saveRule($actor,
            Warehouse::findOrFail($data['warehouse_id'] ?? $rule->warehouse_id), Product::findOrFail($data['product_id'] ?? $rule->product_id),
            (string) $data['min_qty'], (string) $data['max_qty']), 'Saved');

        return $table
            ->columns([
                TextColumn::make('product.name')->label('Product')->searchable()->description(fn (ReorderRule $record) => $record->product->sku),
                TextColumn::make('warehouse.code')->label('Warehouse'),
                TextColumn::make('on_hand')->label('On hand')->state(fn (ReorderRule $record) => Fields::qty(DB::table('stock_levels')
                    ->where('warehouse_id', $record->warehouse_id)->where('product_id', $record->product_id)->value('on_hand'))),
                TextColumn::make('min_qty')->label('Min')->formatStateUsing(fn ($state) => Fields::qty($state)),
                TextColumn::make('max_qty')->label('Max')->formatStateUsing(fn ($state) => Fields::qty($state)),
            ])
            ->filters([SelectFilter::make('warehouse_id')->label('Warehouse')->options(fn () => Warehouse::orderBy('code')->pluck('code', 'id'))])
            ->headerActions([CreateAction::make()->using(fn (array $data): Model => $save($data))])
            ->recordActions([EditAction::make()->using(fn (ReorderRule $record, array $data): Model => $save($data, $record))]);
    }

    public static function getPages(): array
    {
        return ['index' => ListReorderRules::route('/')];
    }
}
