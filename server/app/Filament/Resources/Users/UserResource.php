<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\RelationManagers\AccessGrantsRelationManager;
use App\Filament\Support\Erp;
use App\Filament\Support\GlobalSearch;
use App\Models\User;
use App\Security\SecurityLevel;
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
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

class UserResource extends Resource
{
    use GlobalSearch;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'username', 'email'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return static::searchDetails(['Username' => $record->username]);
    }

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'People & access';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return Erp::can('admin.users.view');
    }

    public static function canCreate(): bool
    {
        return Erp::can('admin.users.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return Erp::can('admin.users.manage') && $record->security_level <= Erp::user()->security_level;
    }

    public static function canDelete(Model $record): bool
    {
        return false; // deactivate instead: users are named on audit history
    }

    public static function getEloquentQuery(): Builder
    {
        // Users are not tenant-scoped by a global scope (auth needs them first), so filter here.
        return parent::getEloquentQuery()->where('tenant_id', Erp::user()?->tenant_id);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('name')->label('Full name')->required()->maxLength(120),
                TextInput::make('username')->autocomplete('off')->required()->maxLength(60)->regex('/^[a-z0-9._-]+$/i')
                    ->helperText('Used to sign in on a till'),
                TextInput::make('email')->email()->autocomplete('off')->required()->maxLength(255),
                Select::make('security_level')->label('Security level (ceiling)')->required()->default(2)
                    ->options(fn () => SecurityLevel::options(min(Erp::user()->security_level, 6)))
                    ->helperText('Roles above this level never apply to the person, even if granted.'),
                TextInput::make('password')->password()->revealable()->autocomplete('new-password')
                    ->label(fn (string $operation) => $operation === 'create' ? 'Password' : 'New password (leave blank to keep)')
                    ->required(fn (string $operation) => $operation === 'create')
                    ->rule(Password::min(12))->dehydrated(fn ($state) => filled($state)),
                Toggle::make('is_active')->label('Active')->visibleOn('edit')
                    ->helperText('Deactivating signs the person out everywhere, including tills.'),
                TextInput::make('reason')->label('Reason for change')->maxLength(255)->visibleOn('edit')->dehydrated(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('username')->searchable(),
                TextColumn::make('email')->searchable()->toggleable(),
                TextColumn::make('security_level')->label('Level')->formatStateUsing(fn ($state) => 'L'.$state)->sortable(),
                IconColumn::make('mfa')->label('2FA')->boolean()
                    ->state(fn (User $r) => $r->app_authentication_secret !== null),
                IconColumn::make('has_pin')->label('PIN')->boolean()->state(fn (User $r) => $r->pin_hash !== null),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('last_login_at')->label('Last till sign-in')->since()->toggleable(),
            ])
            ->filters([TernaryFilter::make('is_active')->label('Active')])
            ->defaultSort('name')
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [AccessGrantsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
