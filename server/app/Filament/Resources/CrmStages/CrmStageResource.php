<?php

namespace App\Filament\Resources\CrmStages;

use App\Domain\Crm\Crm;
use App\Filament\Resources\CrmStages\Pages\ListCrmStages;
use App\Filament\Support\Erp;
use App\Models\CrmDeal;
use App\Models\CrmStage;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** CRM → Pipeline stages: the steps a deal goes through, each with its chance of winning; one Won and one Lost. */
class CrmStageResource extends Resource
{
    protected static ?string $model = CrmStage::class;

    protected static ?string $slug = 'crm-stages';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'CRM';

    protected static ?string $navigationLabel = 'Pipeline stages';

    protected static ?string $modelLabel = 'stage';

    protected static ?int $navigationSort = 6;

    public static function canViewAny(): bool
    {
        return Crm::enabled() && Erp::canAnywhere('crm.setup');
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
        return false; // deals refer to stages: switch one off instead
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(60),
            Select::make('kind')->label('Kind')->options(CrmStage::KINDS)->default('open')->required()->live()->selectablePlaceholder(false),
            TextInput::make('probability')->label('Chance of winning (%)')->numeric()->minValue(0)->maxValue(100)->default(10)
                ->visible(fn (Get $get) => $get('kind') === 'open')->helperText('Used for the weighted value of the pipeline.'),
            TextInput::make('sort')->label('Order')->numeric()->helperText('Stages show from the lowest number.'),
            Toggle::make('is_active')->label('In use')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        $save = fn (?CrmStage $record, array $data): Model => Erp::run(fn ($actor) => app(Crm::class)->saveStage($actor, $record ?? new CrmStage, $data), 'Stage saved');

        return $table
            ->columns([
                TextColumn::make('sort')->label('Order')->color('gray'),
                TextColumn::make('name')->weight('bold'),
                TextColumn::make('kind')->badge()->formatStateUsing(fn ($state) => CrmStage::KINDS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'won' => 'success', 'lost' => 'gray', default => 'info'
                    }),
                TextColumn::make('probability')->label('Chance')->suffix(' %'),
                TextColumn::make('deals')->label('Open deals')->state(fn (CrmStage $s) => CrmDeal::where('stage_id', $s->id)->where('status', 'open')->count()),
                IconColumn::make('is_active')->boolean()->label('In use'),
            ])
            ->defaultSort('sort')
            ->pushHeaderActions([CreateAction::make()->label('New stage')->using(fn (array $data) => $save(null, $data))])
            ->recordActions([EditAction::make()->using(fn (CrmStage $record, array $data) => $save($record, $data))]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCrmStages::route('/')];
    }
}
