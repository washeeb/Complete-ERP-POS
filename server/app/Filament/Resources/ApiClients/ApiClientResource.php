<?php

namespace App\Filament\Resources\ApiClients;

use App\Domain\Api\ApiClients;
use App\Filament\Resources\ApiClients\Pages\ListApiClients;
use App\Filament\Support\Erp;
use App\Models\ApiClient;
use App\Models\Branch;
use App\Models\User;
use App\Models\WebhookEndpoint;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Other systems that use the public API, their scopes and keys, and the webhooks they receive. */
class ApiClientResource extends Resource
{
    protected static ?string $model = ApiClient::class;

    protected static ?string $modelLabel = 'API client';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'API clients';

    protected static ?int $navigationSort = 8;

    public static function canViewAny(): bool
    {
        return Erp::can('api.manage');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** Show a new key once, with a copy button and a warning that it will not be shown again. */
    public static function showKey(string $name, string $key): void
    {
        Notification::make()->title("Key for {$name} — copy it now, it is not shown again")->body($key)->persistent()->success()
            ->actions([Action::make('copy')->label('Copy')->alpineClickHandler('window.navigator.clipboard.writeText('.json_encode($key).')')])->send();
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('scopes')->badge()->separator(','),
                TextColumn::make('user_id')->label('Acts as')->formatStateUsing(fn ($state) => User::whereKey($state)->value('name')),
                TextColumn::make('branch_id')->label('Branch')->formatStateUsing(fn ($state) => Branch::whereKey($state)->value('code'))->placeholder('All'),
                TextColumn::make('last_used_at')->label('Last used')->since()->placeholder('Never'),
                TextColumn::make('webhooks')->label('Webhooks')->state(fn (ApiClient $r) => WebhookEndpoint::where('api_client_id', $r->id)->where('is_active', true)->count()),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([
                Action::make('key')->label('New key')->icon(Heroicon::OutlinedKey)->color('gray')->visible(fn (ApiClient $r) => $r->is_active)->requiresConfirmation()
                    ->modalDescription('A new key is added (for rotation). Old keys keep working until the client is revoked.')
                    ->action(function (ApiClient $record) {
                        $key = Erp::run(fn ($actor) => app(ApiClients::class)->issueKey($actor, $record));
                        if ($key) {
                            self::showKey($record->name, $key);
                        }
                    }),
                Action::make('webhook')->label('Add webhook')->icon(Heroicon::OutlinedBolt)->color('gray')->visible(fn (ApiClient $r) => $r->is_active)
                    ->schema([TextInput::make('url')->label('Address (https://…)')->required()->url()->maxLength(500),
                        CheckboxList::make('events')->options(ApiClients::EVENTS)->required()])
                    ->action(function (ApiClient $record, array $data) {
                        $r = Erp::run(fn ($actor) => app(ApiClients::class)->addWebhook($actor, $actor->tenantId, $record, $data['url'], $data['events']));
                        if ($r) {
                            self::showKey("webhook {$data['url']} (signing secret)", $r[1]);
                        }
                    }),
                Action::make('revoke')->color('danger')->visible(fn (ApiClient $r) => $r->is_active)
                    ->schema([TextInput::make('reason')->required()->maxLength(255)])
                    ->action(fn (ApiClient $record, array $data) => Erp::run(fn ($actor) => app(ApiClients::class)->revoke($actor, $record, $data['reason']), 'Revoked: its keys stopped working')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListApiClients::route('/')];
    }
}
