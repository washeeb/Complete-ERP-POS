<?php

namespace App\Filament\Resources\Industries;

use App\Domain\Org\IndustryService;
use App\Filament\Resources\Industries\Pages\ListIndustries;
use App\Filament\Support\Erp;
use App\Models\Industry;
use App\Models\LegalEntity;
use App\Modules\ModuleRegistry;
use BackedEnum;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Company → Industries: Pharmacy, Grocery, …; each with its branches, catalogue and dashboard. */
class IndustryResource extends Resource
{
    protected static ?string $model = Industry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Company';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Erp::can('setup.company.manage');
    }

    public static function canCreate(): bool
    {
        return Erp::can('setup.company.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return Erp::can('setup.company.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return false; // branches, products and history point at it; switch it off instead
    }

    public static function form(Schema $schema): Schema
    {
        $modules = fn () => collect(app(ModuleRegistry::class)->all())->filter(fn ($m) => $m->definesIndustry() && $m->isAvailable())
            ->mapWithKeys(fn ($m) => [$m->key => $m->name])->all();

        return $schema->components([
            TextInput::make('code')->required()->maxLength(10)->regex('/^[A-Za-z0-9]{1,10}$/')->helperText('Short, e.g. PHARM'),
            TextInput::make('name')->required()->maxLength(120),
            Select::make('module_key')->label('Industry module its branches run')->required()->options($modules),
            Select::make('legal_entity_id')->label('Legal entity (separate books)')->options(fn () => LegalEntity::pluck('name', 'id'))
                ->placeholder('The company')->helperText('Only when this industry is registered as its own business.'),
            TextInput::make('sort')->label('Order')->numeric()->integer()->default(0),
            Toggle::make('is_active')->label('In use')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        $save = fn (?Industry $record, array $data): Model => Erp::run(fn ($actor) => app(IndustryService::class)->save($actor, $record ?? new Industry, $data), 'Saved');

        return $table
            ->columns([
                TextColumn::make('code'),
                TextColumn::make('name'),
                TextColumn::make('module_key')->label('Module')->formatStateUsing(fn (string $state) => app(ModuleRegistry::class)->has($state) ? app(ModuleRegistry::class)->get($state)->name : $state),
                TextColumn::make('branches_count')->counts('branches')->label('Branches'),
                TextColumn::make('legalEntity.name')->label('Books')->placeholder('The company'),
                IconColumn::make('is_active')->boolean()->label('In use'),
            ])
            ->defaultSort('sort')
            ->headerActions([CreateAction::make()->label('New industry')->using(fn (array $data) => $save(null, $data))->visible(fn () => Erp::can('setup.company.manage'))])
            ->recordActions([EditAction::make()->using(fn (Industry $record, array $data) => $save($record, $data))]);
    }

    public static function getPages(): array
    {
        return ['index' => ListIndustries::route('/')];
    }
}
