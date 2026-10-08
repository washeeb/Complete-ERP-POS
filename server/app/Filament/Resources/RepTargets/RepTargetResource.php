<?php

namespace App\Filament\Resources\RepTargets;

use App\Domain\Distribution\RouteService;
use App\Domain\Modules\ModuleService;
use App\Filament\Resources\RepTargets\Pages\ListRepTargets;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\RepTarget;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Monthly sales targets per rep (before tax), and optionally a number of visits. */
class RepTargetResource extends Resource
{
    protected static ?string $model = RepTarget::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Distribution';

    protected static ?string $navigationLabel = 'Rep targets';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('distribution.routes') && ModuleService::enabledAnywhere('distribution');
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Select::make('user_id')->label('Rep')->required()->searchable()->options(fn () => User::where('is_active', true)->orderBy('name')->pluck('name', 'id')),
            TextInput::make('year')->required()->numeric()->default(now()->year),
            Select::make('month')->required()->options(collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => now()->setMonth($m)->format('F')]))->default(now()->month),
            TextInput::make('target')->label('Sales target (before tax)')->required()->numeric()->minValue(0),
            TextInput::make('visit_target')->label('Visits target (optional)')->numeric()->minValue(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('year', 'desc')
            ->columns([
                TextColumn::make('user_id')->label('Rep')->formatStateUsing(fn ($state) => User::whereKey($state)->value('name')),
                TextColumn::make('period')->label('Month')->state(fn (RepTarget $r) => now()->setDate($r->year, $r->month, 1)->format('F Y')),
                TextColumn::make('target_minor')->label('Sales target')->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
                TextColumn::make('visit_target')->label('Visits target')->placeholder('—'),
            ])
            ->recordActions([EditAction::make()->mutateRecordDataUsing(fn (array $data, RepTarget $record) => $data + ['target' => $record->target_minor / 100])
                ->using(fn (RepTarget $record, array $data) => Erp::run(fn ($actor) => app(RouteService::class)->setTarget($actor, $data)))]);
    }

    public static function getPages(): array
    {
        return ['index' => ListRepTargets::route('/')];
    }
}
