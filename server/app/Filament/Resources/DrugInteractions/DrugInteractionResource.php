<?php

namespace App\Filament\Resources\DrugInteractions;

use App\Domain\Modules\ModuleService;
use App\Domain\Pharmacy\PharmacyService;
use App\Filament\Resources\DrugInteractions\Pages\ListDrugInteractions;
use App\Filament\Support\Erp;
use App\Models\DrugInteraction;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** The local table of ingredient pairs that interact; the till warns the pharmacist when both are dispensed together. */
class DrugInteractionResource extends Resource
{
    protected static ?string $model = DrugInteraction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Pharmacy';

    protected static ?string $navigationLabel = 'Drug interactions';

    protected static ?string $modelLabel = 'interaction';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return (Erp::canAnywhere('pharmacy.manage') || Erp::canAnywhere('pharmacy.dispense')) && ModuleService::enabledAnywhere('pharmacy');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('pharmacy.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return Erp::canAnywhere('pharmacy.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return false; // a rule that no longer applies is set to "minor" with a note, so past warnings stay explainable
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('ingredient_a')->label('Ingredient')->required()->maxLength(120),
            TextInput::make('ingredient_b')->label('Interacts with')->required()->maxLength(120),
            Select::make('severity')->options(DrugInteraction::SEVERITIES)->required(),
            TextInput::make('note')->label('What to do')->required()->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('ingredient_a')
            ->columns([
                TextColumn::make('ingredient_a')->label('Ingredient')->searchable(),
                TextColumn::make('ingredient_b')->label('Interacts with')->searchable(),
                TextColumn::make('severity')->badge()->formatStateUsing(fn ($state) => DrugInteraction::SEVERITIES[$state] ?? $state)
                    ->color(fn ($state) => ['major' => 'danger', 'moderate' => 'warning'][$state] ?? 'gray'),
                TextColumn::make('note')->label('What to do')->wrap(),
            ])
            ->filters([SelectFilter::make('severity')->options(DrugInteraction::SEVERITIES)])
            ->recordActions([EditAction::make()->using(fn (DrugInteraction $record, array $data) => Erp::run(fn ($actor) => app(PharmacyService::class)->saveInteraction($actor, $record, $data)))]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDrugInteractions::route('/')];
    }
}
