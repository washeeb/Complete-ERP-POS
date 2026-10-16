<?php

namespace App\Filament\Resources\CustomerOrders;

use App\Domain\Sales\CustomerOrderService;
use App\Domain\Sales\DeliveryService;
use App\Filament\Resources\CustomerOrders\Pages\CreateCustomerOrder;
use App\Filament\Resources\CustomerOrders\Pages\EditCustomerOrder;
use App\Filament\Resources\CustomerOrders\Pages\ListCustomerOrders;
use App\Filament\Resources\CustomerOrders\Pages\ViewCustomerOrder;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Support\Money;
use App\Support\Qty;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Quotations and customer (B2B / wholesale) orders: quote → order → deliveries (each raises its invoice). */
class CustomerOrderResource extends Resource
{
    protected static ?string $model = CustomerOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Quotes & orders';

    protected static ?string $modelLabel = 'quotation';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('sales.orders.view');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('sales.orders.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return $record->status === 'quotation' && Erp::can('sales.orders.manage', $record->warehouseModel());
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = Erp::branchIds('sales.orders.view');

        return parent::getEloquentQuery()->with('customer')->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()->where('status', 'on_hold')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function money(?int $minor, CustomerOrder $o): string
    {
        return Money::display((int) $minor, $o->currency_code);
    }

    public static function label(CustomerOrder $o): string
    {
        return $o->number ? "{$o->number} (from {$o->quote_number})" : $o->quote_number;
    }

    public static function toFormData(CustomerOrder $o): array
    {
        return ['lines' => $o->lines()->get()->map(fn ($l) => ['product_uom_id' => $l->product_uom_id, 'qty' => Fields::qty($l->qty)])->all()];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(3)->schema([
                Select::make('customer_id')->label('Customer')->required()->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Customer::where('is_active', true)->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")
                        ->orWhere('code', 'ilike', "%{$search}%")->when(preg_replace('/\D/', '', $search) !== '', fn ($x) => $x->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $search).'%')))->limit(30)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Customer::find($value)?->name),
                Select::make('warehouse_id')->label('Supply from')->required()->options(fn () => Fields::warehouses('sales.orders.manage')),
                DatePicker::make('valid_until')->label('Quote valid until')->default(now()->addDays(14)),
                TextInput::make('customer_ref')->label('Customer’s PO / reference')->maxLength(60),
                Textarea::make('note')->columnSpan(2),
            ]),
            Section::make('Lines')->description('Prices come from the customer’s price list (with quantity tiers) when you save.')->schema([
                Repeater::make('lines')->hiddenLabel()->columns(3)->minItems(1)->addActionLabel('Add line')->schema([
                    Fields::productUnit()->columnSpan(2),
                    TextInput::make('qty')->label('Quantity')->required()->numeric()->minValue(0.001),
                ]),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('label')->label('Document')->state(fn (CustomerOrder $record) => self::label($record))->weight('bold'),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => CustomerOrder::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'on_hold' => 'danger', 'confirmed', 'partially_delivered' => 'info', 'delivered' => 'success', 'lost', 'cancelled' => 'gray', default => 'warning'
                    }),
                TextEntry::make('customer.name')->label('Customer'),
                TextEntry::make('total_minor')->label('Total')->weight('bold')->formatStateUsing(fn ($state, CustomerOrder $record) => self::money($state, $record)),
                TextEntry::make('customer_ref')->label('Customer ref.')->placeholder('—'),
                TextEntry::make('valid_until')->label('Quote valid until')->date()->placeholder('—'),
                TextEntry::make('created_by_name')->asPerson('created_by')->label('Entered by'),
                TextEntry::make('released_by_name')->label('Credit hold released by')->placeholder('—'),
                TextEntry::make('hold_reason')->label('On hold because')->color('danger')->visible(fn (CustomerOrder $record) => $record->status === 'on_hold')->columnSpanFull(),
                TextEntry::make('close_reason')->label('Closed because')->visible(fn (CustomerOrder $record) => (bool) $record->close_reason)->columnSpanFull(),
                TextEntry::make('note')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Lines')->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->columns(6)->schema([
                    TextEntry::make('name')->columnSpan(2)->formatStateUsing(fn ($state, $record) => "{$record->sku} · {$state}"),
                    TextEntry::make('qty')->label('Ordered')->formatStateUsing(fn ($state, $record) => Fields::qty($state, $record->uom_code)),
                    TextEntry::make('delivered_qty')->label('Delivered')->formatStateUsing(fn ($state, $record) => Fields::qty($state, $record->uom_code)),
                    TextEntry::make('unit_price_minor')->label('Price')->formatStateUsing(fn ($state, $livewire) => self::money($state, $livewire->getRecord())),
                    TextEntry::make('total_minor')->label('Line total')->formatStateUsing(fn ($state, $livewire) => self::money($state, $livewire->getRecord())),
                ]),
            ]),
        ]);
    }

    /** @return list<Action> */
    public static function orderActions(): array
    {
        $svc = fn () => app(CustomerOrderService::class);

        return [
            Action::make('confirm')->label('Turn into order')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                ->visible(fn (CustomerOrder $record) => $record->status === 'quotation' && Erp::can('sales.orders.manage', $record->warehouseModel()))
                ->requiresConfirmation()->modalDescription('The customer accepted. Credit customers are checked against their limit and overdue invoices.')
                ->action(function (CustomerOrder $record) use ($svc) {
                    $o = Erp::run(fn ($actor) => $svc()->confirm($actor, $record));
                    $o->status === 'on_hold'
                        ? Notification::make()->warning()->title("{$o->number} is on credit hold")->body($o->hold_reason)->persistent()->send()
                        : Notification::make()->success()->title("Order {$o->number} confirmed")->send();
                }),
            Action::make('release')->label('Release credit hold')->icon(Heroicon::OutlinedLockOpen)->color('warning')
                ->visible(fn (CustomerOrder $record) => $record->status === 'on_hold' && Erp::can('customers.credit.override', $record->warehouseModel()))
                ->modalDescription(fn (CustomerOrder $record) => $record->hold_reason)
                ->schema([TextInput::make('reason')->label('Why it is safe to go ahead')->required()->maxLength(255)])
                ->action(fn (CustomerOrder $record, array $data) => Erp::run(fn ($actor) => $svc()->release($actor, $record, $data['reason']), 'Released')),
            Action::make('deliver')->label('Deliver')->icon(Heroicon::OutlinedTruck)->color('success')
                ->visible(fn (CustomerOrder $record) => $record->isDeliverable() && Erp::can('sales.deliver', $record->warehouseModel()))
                ->modalWidth('3xl')->modalDescription('Takes the goods out of stock and raises the invoice for what is delivered now.')
                ->fillForm(fn (CustomerOrder $record) => ['lines' => $record->lines()->get()->map(fn ($l) => ['line_id' => $l->id,
                    'item' => "{$l->sku} · {$l->name} ({$l->uom_code})",
                    'qty' => Fields::qty(Qty::format(max(0, Qty::toMilli((string) $l->qty) - Qty::toMilli((string) $l->delivered_qty))))])->all()])
                ->schema([
                    Repeater::make('lines')->columns(3)->addable(false)->deletable(false)->reorderable(false)->schema([
                        TextInput::make('item')->disabled()->dehydrated(false)->columnSpan(2),
                        TextInput::make('qty')->label('Deliver now')->numeric()->minValue(0)->required(),
                        Hidden::make('line_id'),
                    ]),
                    TextInput::make('delivered_to')->label('Received by')->maxLength(255),
                    TextInput::make('vehicle')->label('Vehicle')->maxLength(40),
                ])
                ->action(function (CustomerOrder $record, array $data) {
                    $r = Erp::run(fn ($actor) => app(DeliveryService::class)->deliver($actor, $record,
                        collect($data['lines'])->pluck('qty', 'line_id')->map(fn ($q) => (string) $q)->all(),
                        ['delivered_to' => $data['delivered_to'] ?? null, 'vehicle' => $data['vehicle'] ?? null]));
                    Notification::make()->success()->title("{$r['delivery']->number} delivered")
                        ->body("Invoice {$r['invoice']->number}: ".Money::display($r['invoice']->total_minor, $r['invoice']->currency_code))->send();
                }),
            Action::make('print')->label('Print')->icon(Heroicon::OutlinedPrinter)->color('gray')
                ->url(fn (CustomerOrder $record) => route('print.order', $record), shouldOpenInNewTab: true),
            Action::make('invoices')->label('Invoices')->icon(Heroicon::OutlinedDocumentText)->color('gray')
                ->visible(fn (CustomerOrder $record) => in_array($record->status, ['partially_delivered', 'delivered'], true))
                ->url(fn (CustomerOrder $record) => InvoiceResource::getUrl('index', ['tableSearch' => $record->number])),
            Action::make('cancel')->label(fn (CustomerOrder $record) => $record->status === 'quotation' ? 'Mark lost' : 'Cancel order')
                ->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->visible(fn (CustomerOrder $record) => in_array($record->status, ['quotation', 'on_hold', 'confirmed', 'partially_delivered'], true)
                    && Erp::can('sales.orders.manage', $record->warehouseModel()))
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(fn (CustomerOrder $record, array $data) => Erp::run(fn ($actor) => $svc()->cancel($actor, $record, $data['reason']), 'Done')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('quote_number')->label('Quote')->searchable(),
                TextColumn::make('number')->label('Order')->searchable()->placeholder('—'),
                TextColumn::make('customer.name')->label('Customer')->searchable(),
                TextColumn::make('total_minor')->label('Total')->alignEnd()->formatStateUsing(fn ($state, CustomerOrder $record) => self::money($state, $record)),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => CustomerOrder::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'on_hold' => 'danger', 'confirmed', 'partially_delivered' => 'info', 'delivered' => 'success', 'lost', 'cancelled' => 'gray', default => 'warning'
                    }),
                TextColumn::make('created_at')->label('Created')->since()->sortable(),
            ])
            ->filters([SelectFilter::make('status')->options(CustomerOrder::STATUSES)->multiple()])
            ->defaultSort('created_at', 'desc')
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerOrders::route('/'),
            'create' => CreateCustomerOrder::route('/create'),
            'view' => ViewCustomerOrder::route('/{record}'),
            'edit' => EditCustomerOrder::route('/{record}/edit'),
        ];
    }
}
