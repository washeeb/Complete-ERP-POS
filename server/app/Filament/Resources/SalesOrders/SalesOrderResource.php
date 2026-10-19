<?php

namespace App\Filament\Resources\SalesOrders;

use App\Domain\Channels\OnlineOrderService;
use App\Filament\Resources\SalesOrders\Pages\ListSalesOrders;
use App\Filament\Resources\SalesOrders\Pages\ViewSalesOrder;
use App\Filament\Support\Erp;
use App\Filament\Support\GlobalSearch;
use App\Models\SalesOrder;
use App\Support\Money;
use App\Support\Qty;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Online orders from every channel: reserved on arrival, stock issued only when handed over. */
class SalesOrderResource extends Resource
{
    use GlobalSearch;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getGloballySearchableAttributes(): array
    {
        return ['number'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return static::searchDetails(['Status' => $record->status]);
    }

    protected static ?string $model = SalesOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = 'Online';

    protected static ?string $navigationLabel = 'Online orders';

    protected static ?string $modelLabel = 'online order';

    protected static ?int $navigationSort = 1;

    public const PAYMENT_STATUSES = ['paid' => 'Paid', 'pay_at_pickup' => 'Pay at pickup', 'unpaid' => 'Unpaid', 'refunded' => 'Refunded'];

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('orders.view');
    }

    public static function canCreate(): bool
    {
        return false; // orders come from channels
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = Erp::branchIds('orders.view');

        return parent::getEloquentQuery()->with('channel', 'branch')->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()->whereIn('status', ['pending', 'confirmed', 'ready'])->count();

        return $n ? (string) $n : null;
    }

    private static function money(?int $minor, SalesOrder $o): string
    {
        return Money::display((int) $minor, $o->currency_code);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('Order'),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => SalesOrder::STATUSES[$state] ?? $state),
                TextEntry::make('channel.name')->label('Channel'),
                TextEntry::make('placed_at')->label('Placed')->dateTime(),
                TextEntry::make('customer_name')->label('Customer'),
                TextEntry::make('customer_phone')->label('Phone')->placeholder('—'),
                TextEntry::make('customer_email')->label('Email')->placeholder('—'),
                TextEntry::make('fulfilment')->formatStateUsing(fn ($state, SalesOrder $record) => ($state === 'pickup' ? 'Pickup' : 'Delivery').' · '.$record->branch->name),
                TextEntry::make('payment_status')->label('Payment')->badge()
                    ->formatStateUsing(fn ($state) => self::PAYMENT_STATUSES[$state] ?? $state)
                    ->color(fn ($state) => $state === 'paid' ? 'success' : 'warning'),
                TextEntry::make('payment_reference')->label('Payment ref.')->placeholder('—'),
                TextEntry::make('total_minor')->label('Total')->weight('bold')->formatStateUsing(fn ($state, SalesOrder $record) => self::money($state, $record)),
                TextEntry::make('short_stock')->label('Stock')->formatStateUsing(fn ($state) => $state ? 'Not all reserved — see the review queue' : 'Reserved')
                    ->color(fn ($state) => $state ? 'danger' : 'success'),
                TextEntry::make('note')->placeholder('—')->columnSpanFull(),
                TextEntry::make('cancel_reason')->label('Cancelled because')->visible(fn (SalesOrder $record) => $record->status === 'cancelled')->columnSpanFull(),
            ]),
            Section::make('Items')->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->columns(5)->schema([
                    TextEntry::make('name')->columnSpan(2)->formatStateUsing(fn ($state, $record) => "{$record->sku} · {$state}"),
                    TextEntry::make('qty')->formatStateUsing(fn ($state, $record) => rtrim(rtrim(Qty::format(Qty::toMilli((string) $state)), '0'), '.').' '.$record->uom_code),
                    TextEntry::make('unit_price_minor')->label('Unit price')->formatStateUsing(fn ($state, $livewire) => self::money($state, $livewire->getRecord())),
                    TextEntry::make('line_total_minor')->label('Line total')->formatStateUsing(fn ($state, $livewire) => self::money($state, $livewire->getRecord())),
                ]),
            ]),
        ]);
    }

    /** @return list<Action> shared by the table row and the view page */
    public static function orderActions(): array
    {
        $orders = fn () => app(OnlineOrderService::class);

        return [
            Action::make('ready')->label('Mark ready')->icon(Heroicon::OutlinedArchiveBox)
                ->visible(fn (SalesOrder $record) => $record->status === 'confirmed' && Erp::can('orders.manage', $record->branch))
                ->action(fn (SalesOrder $record) => Erp::run(fn ($actor) => $orders()->markReady($actor, $record), 'Marked ready for pickup')),
            Action::make('fulfil')->label('Hand over')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                ->visible(fn (SalesOrder $record) => in_array($record->status, ['confirmed', 'ready'], true) && Erp::can('orders.manage', $record->branch))
                ->modalDescription(fn (SalesOrder $record) => 'Issues the stock and closes the order. Total '.self::money($record->total_minor, $record).'.')
                ->schema(fn (SalesOrder $record) => $record->payment_status === 'paid' ? [] : [
                    Select::make('payment_method')->label('Paid with')->options(OnlineOrderService::PAYMENT_METHODS)->required()->live(),
                    TextInput::make('payment_reference')->label('Reference (MoMo/card)')->maxLength(80)
                        ->visible(fn (Get $get) => in_array($get('payment_method'), ['momo', 'card'], true)),
                ])
                ->action(fn (SalesOrder $record, array $data) => Erp::run(fn ($actor) => $orders()->fulfil($actor, $record,
                    $data['payment_method'] ?? null, $data['payment_reference'] ?? null), 'Handed over')),
            Action::make('cancel')->label('Cancel order')->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->visible(fn (SalesOrder $record) => $record->isOpen() && Erp::can('orders.manage', $record->branch))
                ->modalDescription('Releases the reserved stock. The customer is not notified automatically yet.')
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(fn (SalesOrder $record, array $data) => Erp::run(fn ($actor) => $orders()->cancel($actor, $record, $data['reason']), 'Cancelled')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Order')->searchable()->description(fn (SalesOrder $record) => $record->channel->name),
                TextColumn::make('placed_at')->label('Placed')->since()->sortable(),
                TextColumn::make('customer_name')->label('Customer')->searchable()->description(fn (SalesOrder $record) => $record->customer_phone),
                TextColumn::make('branch.code')->label('Branch'),
                TextColumn::make('total_minor')->label('Total')->alignEnd()->formatStateUsing(fn ($state, SalesOrder $record) => self::money($state, $record)),
                TextColumn::make('payment_status')->label('Payment')->badge()->formatStateUsing(fn ($state) => self::PAYMENT_STATUSES[$state] ?? $state)
                    ->color(fn ($state) => $state === 'paid' ? 'success' : 'warning'),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => SalesOrder::STATUSES[$state] ?? $state)
                    ->color(fn ($state, SalesOrder $record) => $record->short_stock && $record->isOpen() ? 'danger' : match ($state) {
                        'fulfilled' => 'success', 'cancelled' => 'gray', 'ready' => 'info', default => 'warning'
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->options(SalesOrder::STATUSES)->multiple()->default(['pending', 'confirmed', 'ready']),
                SelectFilter::make('channel_id')->label('Channel')->relationship('channel', 'name'),
            ])
            ->defaultSort('placed_at', 'desc')
            ->recordActions([ViewAction::make(), ...self::orderActions()])
            ->poll('30s')
            ->emptyStateHeading('No online orders');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesOrders::route('/'),
            'view' => ViewSalesOrder::route('/{record}'),
        ];
    }
}
