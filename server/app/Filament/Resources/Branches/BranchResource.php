<?php

namespace App\Filament\Resources\Branches;

use App\Domain\Modules\ModuleService;
use App\Filament\Resources\Branches\Pages\CreateBranch;
use App\Filament\Resources\Branches\Pages\EditBranch;
use App\Filament\Resources\Branches\Pages\ListBranches;
use App\Filament\Resources\Branches\RelationManagers\TillsRelationManager;
use App\Filament\Resources\Branches\RelationManagers\WarehousesRelationManager;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\Industry;
use App\Models\LegalEntity;
use App\Modules\ModuleRegistry;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Company';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('setup.company.manage');
    }

    public static function canCreate(): bool
    {
        return Erp::can('setup.company.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return Erp::can('setup.company.manage', $record);
    }

    public static function canDelete(Model $record): bool
    {
        return false; // branches are deactivated, never deleted (history references them)
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = Erp::branchIds('setup.company.manage');

        return parent::getEloquentQuery()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('code')->required()->maxLength(6)->regex('/^[A-Za-z0-9]{1,6}$/')
                    ->helperText('Appears in receipt and invoice numbers. Cannot be changed later.')
                    ->disabledOn('edit'),
                TextInput::make('name')->required()->maxLength(120),
                Select::make('legal_entity_id')->label('Legal entity')->options(fn () => LegalEntity::pluck('name', 'id')),
                Select::make('industry_id')->label('Industry')->options(fn () => Industry::licensed()->orderBy('sort')->pluck('name', 'id'))
                    ->placeholder('Company-level (head office, central warehouse)')->visibleOn('edit')
                    ->helperText('One industry per branch: switching its industry module on (Company → Modules) sets it too.'),
                TextInput::make('phone')->tel()->maxLength(30),
                TextInput::make('address')->maxLength(255)->columnSpanFull(),
                TextInput::make('tills')->label('Tills to create')->numeric()->minValue(0)->maxValue(50)->default(1)
                    ->required()->visibleOn('create')->dehydrated(),
                Toggle::make('is_active')->label('Active')->default(true)->visibleOn('edit')
                    ->helperText('Inactive branches keep their history but cannot trade.'),
                TextInput::make('reason')->label('Reason for change')->maxLength(255)->visibleOn('edit')->dehydrated(),
            ]),
            Section::make('Pharmacy rules for this branch')->visibleOn('edit')->visible(fn () => ModuleService::enabledAnywhere('pharmacy'))->collapsible()
                ->description('Empty = the company setting (System → Settings → Pharmacy). Set a value only where this branch works differently, e.g. a shop without a pharmacist on duty.')
                ->columns(2)->schema(self::ownSettingFields('pharmacy.')),
        ]);
    }

    /** One field per setting a branch may set for itself; empty follows the company. */
    private static function ownSettingFields(string $prefix): array
    {
        $settings = app(Settings::class);
        $fields = [];
        foreach (Settings::branchKeys($prefix) as $key) {
            $def = Settings::DEFINITIONS[$key];
            $company = $settings->get($key, Erp::user()?->tenant_id);
            $field = self::ownField($key);
            $fields[] = match ($def['type']) {
                'choice' => Select::make($field)->options($def['options'])->placeholder('Company: '.($def['options'][$company] ?? $company)),
                'bool' => Select::make($field)->options(['1' => 'Yes', '0' => 'No'])->placeholder('Company: '.($company ? 'Yes' : 'No')),
                default => TextInput::make($field)->numeric()->minValue($def['min'] ?? null)->maxValue($def['max'] ?? null)->placeholder('Company: '.$company),
            };
            $fields[array_key_last($fields)]->label($def['label'])->helperText($def['help']);
        }

        return $fields;
    }

    public static function ownField(string $key): string
    {
        return 'own_'.str_replace('.', '__', $key);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->sortable()->searchable(),
                TextColumn::make('name')->sortable()->searchable(),
                IconColumn::make('is_hq')->label('HQ')->boolean(),
                TextColumn::make('industry.name')->label('Industry')->placeholder('Company-level'),
                TextColumn::make('tills_count')->counts('tills')->label('Tills'),
                TextColumn::make('modules_list')->label('Modules')
                    ->state(fn (Branch $r) => $r->modules->where('status', '!=', 'disabled')
                        ->map(fn ($m) => (app(ModuleRegistry::class)->has($m->module_key) ? app(ModuleRegistry::class)->get($m->module_key)->name : $m->module_key)
                            .($m->status === 'reserved' ? ' (reserved)' : ''))->implode(', ')),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with('modules'))
            ->defaultSort('code')
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [WarehousesRelationManager::class, TillsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBranches::route('/'),
            'create' => CreateBranch::route('/create'),
            'edit' => EditBranch::route('/{record}/edit'),
        ];
    }
}
