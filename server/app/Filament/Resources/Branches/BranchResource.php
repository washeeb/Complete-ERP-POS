<?php

namespace App\Filament\Resources\Branches;

use App\Filament\Resources\Branches\Pages\CreateBranch;
use App\Filament\Resources\Branches\Pages\EditBranch;
use App\Filament\Resources\Branches\Pages\ListBranches;
use App\Filament\Resources\Branches\RelationManagers\TillsRelationManager;
use App\Filament\Resources\Branches\RelationManagers\WarehousesRelationManager;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\LegalEntity;
use App\Modules\ModuleRegistry;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
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
                TextInput::make('phone')->tel()->maxLength(30),
                TextInput::make('address')->maxLength(255)->columnSpanFull(),
                TextInput::make('tills')->label('Tills to create')->numeric()->minValue(0)->maxValue(50)->default(1)
                    ->required()->visibleOn('create')->dehydrated(),
                Toggle::make('is_active')->label('Active')->default(true)->visibleOn('edit')
                    ->helperText('Inactive branches keep their history but cannot trade.'),
                TextInput::make('reason')->label('Reason for change')->maxLength(255)->visibleOn('edit')->dehydrated(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->sortable()->searchable(),
                TextColumn::make('name')->sortable()->searchable(),
                IconColumn::make('is_hq')->label('HQ')->boolean(),
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
