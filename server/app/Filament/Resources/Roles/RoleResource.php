<?php

namespace App\Filament\Resources\Roles;

use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Support\Erp;
use App\Models\Permission;
use App\Models\Role;
use App\Security\SecurityLevel;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
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
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'People & access';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Erp::can('admin.users.view');
    }

    public static function canCreate(): bool
    {
        return Erp::can('admin.roles.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return Erp::can('admin.roles.manage') && $record->security_level <= Erp::user()->security_level;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('name')->required()->maxLength(80),
                Select::make('security_level')->label('Level')->required()
                    ->options(fn () => SecurityLevel::options(min(Erp::user()->security_level, 6)))
                    ->helperText('Only people cleared to this level or higher can hold the role.'),
                TextInput::make('description')->maxLength(255)->columnSpanFull(),
                Toggle::make('is_technical')->label('Technical role (IT): may hold only technical permissions')
                    ->disabledOn('edit')->columnSpanFull(),
                CheckboxList::make('permissions')->label('Permissions')->columns(2)->columnSpanFull()->bulkToggleable()
                    ->options(fn () => Permission::orderBy('module')->orderBy('key')->get()
                        ->mapWithKeys(fn (Permission $p) => [$p->key => $p->label]))
                    ->descriptions(fn () => Permission::all()->mapWithKeys(fn (Permission $p) => [
                        $p->key => "{$p->key} · min L{$p->min_level}".($p->is_technical ? ' · technical' : ''),
                    ]))
                    ->helperText('You can only add permissions you hold company-wide yourself.'),
                TextInput::make('reason')->label('Reason for change')->maxLength(255)->visibleOn('edit')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('security_level')->label('Level')->formatStateUsing(fn ($s) => 'L'.$s)->sortable(),
                TextColumn::make('permissions_count')->counts('permissions')->label('Permissions'),
                IconColumn::make('is_technical')->label('Technical')->boolean(),
                IconColumn::make('is_system')->label('Seeded')->boolean(),
            ])
            ->defaultSort('security_level', 'desc')
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
