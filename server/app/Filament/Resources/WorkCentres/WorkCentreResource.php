<?php

namespace App\Filament\Resources\WorkCentres;

use App\Domain\Manufacturing\BomService;
use App\Domain\Modules\ModuleService;
use App\Filament\Resources\WorkCentres\Pages\ListWorkCentres;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Branch;
use App\Models\WorkCentre;
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
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Work centres: where production work is done, what an hour there costs (labour and overhead), and how many hours a day it runs. */
class WorkCentreResource extends Resource
{
    protected static ?string $model = WorkCentre::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Manufacturing';

    protected static ?string $navigationLabel = 'Work centres';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('stock.view') && ModuleService::enabledAnywhere('manufacturing');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('manufacturing.setup');
    }

    public static function canEdit(Model $record): bool
    {
        return Erp::canAnywhere('manufacturing.setup');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('code')->required()->maxLength(20),
            TextInput::make('name')->required()->maxLength(255),
            Select::make('branch_id')->label('Branch')->required()->options(fn () => Branch::orderBy('code')->pluck('name', 'id')),
            TextInput::make('hourly_rate')->label('Cost per hour (labour + overhead)')->numeric()->minValue(0)->default(0)
                ->helperText('Wages of the people at this machine plus its running costs (power, wear), per hour. Charged to every order worked here.'),
            TextInput::make('hours_per_day')->label('Hours it runs a day')->numeric()->minValue(0)->maxValue(24)->default(8),
            Toggle::make('is_active')->label('In use')->default(true)->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('code')
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('branch_id')->label('Branch')->formatStateUsing(fn ($state) => Branch::whereKey($state)->value('code')),
                TextColumn::make('hourly_rate_minor')->label('Cost per hour')->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
                TextColumn::make('hours_per_day')->label('Hours a day'),
                IconColumn::make('is_active')->label('In use')->boolean(),
            ])
            ->recordActions([EditAction::make()->mutateRecordDataUsing(fn (array $data, WorkCentre $record) => $data + ['hourly_rate' => $record->hourly_rate_minor / 100])
                ->using(fn (WorkCentre $record, array $data) => Erp::run(fn ($actor) => app(BomService::class)->saveWorkCentre($actor, $record, $data)))]);
    }

    public static function getPages(): array
    {
        return ['index' => ListWorkCentres::route('/')];
    }
}
