<?php

namespace App\Filament\Resources\KitchenStations;

use App\Domain\Modules\ModuleService;
use App\Domain\Restaurant\RestaurantSetup;
use App\Filament\Resources\KitchenStations\Pages\ListKitchenStations;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\KitchenStation;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Where dishes and drinks are prepared; each has a kitchen screen, or prints its tickets. */
class KitchenStationResource extends Resource
{
    protected static ?string $model = KitchenStation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFire;

    protected static string|UnitEnum|null $navigationGroup = 'Restaurant';

    protected static ?string $navigationLabel = 'Kitchen stations';

    protected static ?string $modelLabel = 'kitchen station';

    protected static ?int $navigationSort = 3;

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
        return Erp::can('restaurant.manage', Branch::find($record->branch_id));
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = Erp::branchIds('restaurant.manage');

        return parent::getEloquentQuery()->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('branch_id')->label('Branch')->required()->disabledOn('edit')
                ->options(fn () => Branch::when(Erp::branchIds('restaurant.manage') !== null, fn ($q) => $q->whereIn('id', Erp::branchIds('restaurant.manage')))->orderBy('code')->pluck('name', 'id')),
            TextInput::make('code')->required()->maxLength(20)->placeholder('KITCHEN')->helperText('Products name their station by this code.'),
            TextInput::make('name')->required()->maxLength(255),
            Toggle::make('auto_print')->label('Print tickets (no screen)')->inline(false)
                ->helperText('The kitchen device at this station prints each new ticket instead of only showing it.'),
            Toggle::make('is_active')->label('In use')->default(true)->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('code')
            ->columns([
                TextColumn::make('branch.code')->label('Branch'),
                TextColumn::make('code'),
                TextColumn::make('name'),
                IconColumn::make('auto_print')->label('Prints tickets')->boolean(),
                IconColumn::make('is_active')->label('In use')->boolean(),
            ])
            ->recordActions([EditAction::make()->using(fn (KitchenStation $record, array $data) => Erp::run(fn ($actor) => app(RestaurantSetup::class)->saveStation($actor, $record, $data)))]);
    }

    public static function getPages(): array
    {
        return ['index' => ListKitchenStations::route('/')];
    }
}
