<?php

namespace App\Filament\Resources\SalesChannels;

use App\Domain\Channels\ChannelAdmin;
use App\Domain\Channels\Connectors\ConnectorRegistry;
use App\Domain\Channels\Reconciler;
use App\Filament\Resources\SalesChannels\Pages\CreateSalesChannel;
use App\Filament\Resources\SalesChannels\Pages\EditSalesChannel;
use App\Filament\Resources\SalesChannels\Pages\ListSalesChannels;
use App\Filament\Support\Erp;
use App\Http\Controllers\StorefrontController;
use App\Models\Branch;
use App\Models\ChannelJob;
use App\Models\PriceList;
use App\Models\SalesChannel;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Online channels (spec §5): the built-in shop and connected stores, with their sync health. */
class SalesChannelResource extends Resource
{
    protected static ?string $model = SalesChannel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Online';

    protected static ?string $navigationLabel = 'Channels';

    protected static ?string $modelLabel = 'sales channel';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Erp::can('channels.manage');
    }

    public static function canCreate(): bool
    {
        return Erp::can('channels.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return Erp::can('channels.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return false; // pause instead: orders and mappings refer to the channel
    }

    public static function form(Schema $schema): Schema
    {
        $secretFields = [];
        foreach (array_keys(ConnectorRegistry::availableOptions()) as $type) {
            $connector = app(ConnectorRegistry::class)->for($type);
            foreach ($connector->credentialFields() as $field => $label) {
                $secretFields[] = TextInput::make("secrets.{$type}.{$field}")->label($label)->password()->revealable()->autocomplete('new-password')->maxLength(500)
                    ->visible(fn (Get $get) => $get('type') === $type)
                    ->helperText(fn (?SalesChannel $record) => ! empty($record?->credentials[$field]) ? 'Saved. Leave blank to keep it.' : null);
            }
            if (in_array('order_webhooks', $connector->capabilities(), true)) {
                $secretFields[] = TextInput::make("secrets.{$type}.webhook_secret")->label('Webhook secret')->password()->revealable()->autocomplete('new-password')->maxLength(500)
                    ->visible(fn (Get $get) => $get('type') === $type)
                    ->helperText(fn (?SalesChannel $record) => ($record?->webhook_secret ? 'Saved. Leave blank to keep it. ' : '')
                        .'The same secret you enter in the store’s webhook settings; incoming orders with a different signature are refused.');
            }
        }

        return $schema->columns(1)->components([
            Section::make('Channel')->columns(2)->schema([
                Select::make('type')->options(ConnectorRegistry::availableOptions())->required()->live()
                    ->disabled(fn (?SalesChannel $record) => $record?->exists)->dehydrated(fn (?SalesChannel $record) => ! $record?->exists)
                    ->helperText('Marketplaces (Jumia, Glovo, Bolt Food, WhatsApp) are planned and appear here once their connectors exist.'),
                TextInput::make('name')->required()->maxLength(80),
                Select::make('branch_id')->label('Fulfilling branch')->required()->live()
                    ->options(fn () => Branch::orderBy('code')->pluck('name', 'id'))
                    ->afterStateUpdated(fn ($set) => $set('warehouse_id', null)),
                Select::make('warehouse_id')->label('Stock from warehouse')->required()
                    ->options(fn (Get $get) => Warehouse::where('branch_id', $get('branch_id'))->orderBy('name')->pluck('name', 'id')),
                Select::make('price_list_id')->label('Price list')->required()
                    ->options(fn () => PriceList::orderBy('name')->pluck('name', 'id')),
                TextInput::make('safety_stock')->label('Safety stock (per product)')->numeric()->minValue(0)->default('0')
                    ->helperText('Held back from what the channel may sell, so in-store and offline sales do not oversell online.'),
                TextInput::make('base_url')->label('Store address')->url()->maxLength(255)->placeholder('https://shop.example.com')
                    ->visible(fn (Get $get) => $get('type') && $get('type') !== 'storefront')->columnSpanFull(),
            ]),
            Section::make('Shop page')->columns(2)->visible(fn (Get $get) => $get('type') === 'storefront')->schema([
                TextInput::make('settings.slug')->label('Shop address')->prefix(url('/shop').'/')->maxLength(60)
                    ->helperText('Lowercase letters, digits and dashes. Leave blank to use the name.'),
                TextInput::make('settings.headline')->label('Headline')->maxLength(120)->placeholder(StorefrontController::DEFAULT_TEXT['headline']),
                Textarea::make('settings.intro')->label('Introduction')->maxLength(500)->placeholder(StorefrontController::DEFAULT_TEXT['intro']),
                Textarea::make('settings.pickup_note')->label('Pickup note')->maxLength(500)->placeholder(StorefrontController::DEFAULT_TEXT['pickup_note']),
            ]),
            Section::make('Connection')->description('Secrets are stored encrypted and never shown again.')
                ->visible(fn (Get $get) => $get('type') && $get('type') !== 'storefront')->schema($secretFields),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->description(fn (SalesChannel $r) => app(ConnectorRegistry::class)->label($r->type)),
                TextColumn::make('branch.name')->label('Branch'),
                TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'active' => 'success', 'paused' => 'warning', default => 'gray'
                }),
                TextColumn::make('queue')->label('Updates')->state(fn (SalesChannel $r) => $r->type === 'storefront' ? 'live' : (implode(' · ', array_filter([
                    ($n = ChannelJob::where('channel_id', $r->id)->where('status', 'pending')->count()) ? "{$n} waiting" : null,
                    ($n = ChannelJob::where('channel_id', $r->id)->where('status', 'dead')->count()) ? "{$n} failed" : null,
                ])) ?: 'up to date'))
                    ->color(fn ($state) => str_contains($state, 'failed') ? 'danger' : null),
                TextColumn::make('address')->label('Address / webhook')->copyable()->wrap()
                    ->state(fn (SalesChannel $r) => $r->type === 'storefront'
                        ? url('/shop/'.($r->settings['slug'] ?? ''))
                        : (in_array('order_webhooks', app(ConnectorRegistry::class)->for($r->type)->capabilities(), true) ? url("/api/v1/channels/{$r->id}/webhooks") : '—')),
            ])
            ->recordActions([
                Action::make('activate')->label('Activate')->icon(Heroicon::OutlinedPlay)->color('success')
                    ->visible(fn (SalesChannel $r) => $r->status !== 'active')
                    ->requiresConfirmation()
                    ->modalDescription(fn (SalesChannel $r) => $r->type === 'storefront'
                        ? 'The shop page goes live at its address.'
                        : 'Sends every product, price and stock level to the store now, then keeps it up to date.')
                    ->action(fn (SalesChannel $record) => Erp::run(fn ($actor) => app(ChannelAdmin::class)->activate($actor, $record), 'Activated')),
                Action::make('pause')->label('Pause')->icon(Heroicon::OutlinedPause)->color('warning')
                    ->visible(fn (SalesChannel $r) => $r->status === 'active')
                    ->modalDescription('Stops sending updates and accepting new orders from this channel. Nothing is deleted.')
                    ->schema([TextInput::make('reason')->required()->maxLength(255)])
                    ->action(fn (SalesChannel $record, array $data) => Erp::run(fn ($actor) => app(ChannelAdmin::class)->pause($actor, $record, $data['reason']), 'Paused')),
                EditAction::make(),
                ActionGroup::make([
                    Action::make('reconcile')->label('Compare with store')->icon(Heroicon::OutlinedScale)
                        ->visible(fn (SalesChannel $r) => $r->status === 'active' && in_array('reconcile', app(ConnectorRegistry::class)->for($r->type)->capabilities(), true))
                        ->action(function (SalesChannel $record) {
                            $diffs = Erp::run(fn () => app(Reconciler::class)->run($record));
                            Notification::make()->success()->title($diffs ? count($diffs).' difference(s) found and queued to fix' : 'Store matches')
                                ->body(collect($diffs)->take(10)->map(fn ($d) => "{$d['sku']}: {$d['field']} store {$d['store']}, ours {$d['erp']}")->implode("\n") ?: null)->send();
                        }),
                    Action::make('retry')->label('Retry failed updates')->icon(Heroicon::OutlinedArrowPath)
                        ->visible(fn (SalesChannel $r) => ChannelJob::where('channel_id', $r->id)->where('status', 'dead')->exists())
                        ->modalDescription(fn (SalesChannel $r) => 'Last error: '.ChannelJob::where('channel_id', $r->id)->where('status', 'dead')->latest('updated_at')->value('last_error'))
                        ->action(fn (SalesChannel $record) => Erp::run(fn ($actor) => app(ChannelAdmin::class)->retryFailed($actor, $record), 'Queued again')),
                ]),
            ])
            ->emptyStateHeading('No online channels yet')
            ->emptyStateDescription('Add the built-in shop, or connect a WooCommerce or Shopify store.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesChannels::route('/'),
            'create' => CreateSalesChannel::route('/create'),
            'edit' => EditSalesChannel::route('/{record}/edit'),
        ];
    }
}
