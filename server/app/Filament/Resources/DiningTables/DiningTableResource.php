<?php

namespace App\Filament\Resources\DiningTables;

use App\Domain\Modules\ModuleService;
use App\Domain\Restaurant\RestaurantSetup;
use App\Filament\Resources\DiningTables\Pages\ListDiningTables;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\DiningTable;
use BackedEnum;
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
use UnitEnum;

/** The floor plan: tables per branch, with their area, seats and position on the till's floor view. */
class DiningTableResource extends Resource
{
    protected static ?string $model = DiningTable::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Restaurant';

    protected static ?string $navigationLabel = 'Floor plan';

    protected static ?string $modelLabel = 'table';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('restaurant.manage') && ModuleService::enabledAnywhere('restaurant');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('restaurant.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return Erp::can('restaurant.manage', $record->branch_id ? Branch::find($record->branch_id) : null);
    }

    public static function canDelete(Model $record): bool
    {
        return false; // bills reference tables; switch a table off instead
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = Erp::branchIds('restaurant.manage');

        return parent::getEloquentQuery()->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Select::make('branch_id')->label('Branch')->required()->disabledOn('edit')
                ->options(fn () => Branch::when(Erp::branchIds('restaurant.manage') !== null, fn ($q) => $q->whereIn('id', Erp::branchIds('restaurant.manage')))->orderBy('code')->pluck('name', 'id')),
            TextInput::make('code')->label('Table')->required()->maxLength(20),
            TextInput::make('area')->maxLength(60)->placeholder('Inside, Terrace, Bar…'),
            TextInput::make('seats')->numeric()->required()->default(4)->minValue(1)->maxValue(60),
            TextInput::make('pos_x')->label('Column on the floor view')->numeric()->default(0)->minValue(0)->maxValue(99),
            TextInput::make('pos_y')->label('Row on the floor view')->numeric()->default(0)->minValue(0)->maxValue(99),
            Toggle::make('is_active')->label('In use')->default(true)->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('code')
            ->columns([
                TextColumn::make('branch.code')->label('Branch'),
                TextColumn::make('code')->label('Table')->searchable(),
                TextColumn::make('area')->placeholder('—'),
                TextColumn::make('seats'),
                TextColumn::make('position')->label('Floor view')->state(fn (DiningTable $r) => "column {$r->pos_x}, row {$r->pos_y}"),
                IconColumn::make('is_active')->label('In use')->boolean(),
            ])
            ->filters([SelectFilter::make('branch_id')->label('Branch')->relationship('branch', 'name')])
            ->recordActions([EditAction::make()->using(fn (DiningTable $record, array $data) => Erp::run(fn ($actor) => app(RestaurantSetup::class)->saveTable($actor, $record, $data)))]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDiningTables::route('/')];
    }
}
