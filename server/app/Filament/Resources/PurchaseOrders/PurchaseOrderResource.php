<?php

namespace App\Filament\Resources\PurchaseOrders;

use App\Domain\Purchasing\GoodsReceiptService;
use App\Domain\Purchasing\PurchaseOrderService;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\GlobalSearch;
use App\Filament\Support\QuickCreate;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
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
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
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

class PurchaseOrderResource extends Resource
{
    use GlobalSearch;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'supplier.name'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return static::searchDetails(['Supplier' => $record->supplier?->name, 'Status' => $record->status]);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['supplier']);
    }

    protected static ?string $model = PurchaseOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?string $navigationLabel = 'Purchase orders';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('purchasing.view');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('purchasing.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return $record->status === 'draft' && Erp::can('purchasing.manage', $record->warehouse);
    }

    public static function canDelete(Model $record): bool
    {
        return false; // cancel instead
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = Erp::branchIds('purchasing.view');

        return parent::getEloquentQuery()->with('supplier', 'warehouse')->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()->where('status', 'pending_approval')->count();

        return $n ? (string) $n : null;
    }

    public static function money(?int $minor, PurchaseOrder $po): string
    {
        return Money::display((int) $minor, $po->currency_code);
    }

    public static function toFormData(PurchaseOrder $po): array
    {
        return ['lines' => $po->lines()->get()->map(fn ($l) => ['product_uom_id' => $l->product_uom_id, 'qty' => Fields::qty($l->qty),
            'unit_cost' => Money::format($l->unit_cost_minor, $po->currency_code)])->all()];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(3)->schema([
                QuickCreate::on(Select::make('supplier_id')->label('Supplier')->required()->searchable()
                    ->options(fn () => Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id')), 'supplier'),
                Select::make('warehouse_id')->label('Deliver to')->required()->options(fn () => Fields::warehouses('purchasing.manage')),
                DatePicker::make('expected_on')->label('Expected delivery'),
                Textarea::make('note')->columnSpanFull(),
            ]),
            Section::make('Lines')->description('Costs are before tax. Leave the cost blank to use the supplier’s last cost.')->schema([
                Repeater::make('lines')->hiddenLabel()->columns(4)->minItems(1)->addActionLabel('Add line')->schema([
                    Fields::productUnit()->columnSpan(2),
                    TextInput::make('qty')->label('Quantity')->required()->numeric()->minValue(0.001),
                    TextInput::make('unit_cost')->label('Cost per unit')->numeric()->minValue(0),
                ]),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('Order'),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => PurchaseOrder::STATUSES[$state] ?? $state),
                TextEntry::make('supplier.name')->label('Supplier'),
                TextEntry::make('warehouse.name')->label('Deliver to'),
                TextEntry::make('net_minor')->label('Before tax')->formatStateUsing(fn ($state, PurchaseOrder $record) => self::money($state, $record)),
                TextEntry::make('tax_minor')->label('Tax')->formatStateUsing(fn ($state, PurchaseOrder $record) => self::money($state, $record)),
                TextEntry::make('total_minor')->label('Total')->weight('bold')->formatStateUsing(fn ($state, PurchaseOrder $record) => self::money($state, $record)),
                TextEntry::make('expected_on')->label('Expected')->date()->placeholder('—'),
                TextEntry::make('created_by_name')->asPerson('created_by')->label('Created by'),
                TextEntry::make('approved_by_name')->asPerson('approved_by')->label('Approved by')->placeholder('—'),
                TextEntry::make('rejected_reason')->label('Sent back because')->placeholder('—')->color('danger')->visible(fn (PurchaseOrder $record) => (bool) $record->rejected_reason),
                TextEntry::make('close_reason')->label('Closed because')->visible(fn (PurchaseOrder $record) => (bool) $record->close_reason),
                TextEntry::make('note')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Lines')->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->columns(6)->schema([
                    TextEntry::make('name')->columnSpan(2)->formatStateUsing(fn ($state, $record) => "{$record->sku} · {$state}"),
                    TextEntry::make('qty')->label('Ordered')->formatStateUsing(fn ($state, $record) => Fields::qty($state, $record->uom_code)),
                    TextEntry::make('received_qty')->label('Received')->formatStateUsing(fn ($state, $record) => Fields::qty($state, $record->uom_code)),
                    TextEntry::make('unit_cost_minor')->label('Cost')->formatStateUsing(fn ($state, $livewire) => self::money($state, $livewire->getRecord())),
                    TextEntry::make('total_minor')->label('Total incl. tax')->formatStateUsing(fn ($state, $livewire) => self::money($state, $livewire->getRecord())),
                ]),
            ]),
        ]);
    }

    /** @return list<Action> */
    public static function orderActions(): array
    {
        $svc = fn () => app(PurchaseOrderService::class);

        return [
            Action::make('submit')->label('Submit for approval')->icon(Heroicon::OutlinedPaperAirplane)
                ->visible(fn (PurchaseOrder $record) => $record->status === 'draft' && Erp::can('purchasing.manage', $record->warehouse))
                ->requiresConfirmation()
                ->action(fn (PurchaseOrder $record) => Erp::run(fn ($actor) => $svc()->submit($actor, $record), 'Submitted for approval')),
            Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                ->visible(fn (PurchaseOrder $record) => $record->status === 'pending_approval' && Erp::can('purchasing.approve', $record->warehouse))
                ->requiresConfirmation()
                ->modalDescription(fn (PurchaseOrder $record) => "Approve {$record->number} for ".self::money($record->total_minor, $record).' from '.$record->supplier->name.'?')
                ->action(fn (PurchaseOrder $record) => Erp::run(fn ($actor) => $svc()->approve($actor, $record), 'Approved')),
            Action::make('reject')->label('Send back')->icon(Heroicon::OutlinedArrowUturnLeft)->color('warning')
                ->visible(fn (PurchaseOrder $record) => $record->status === 'pending_approval' && Erp::can('purchasing.approve', $record->warehouse))
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(fn (PurchaseOrder $record, array $data) => Erp::run(fn ($actor) => $svc()->reject($actor, $record, $data['reason']), 'Sent back to draft')),
            Action::make('receive')->label('Receive goods')->icon(Heroicon::OutlinedInboxArrowDown)->color('success')
                ->visible(fn (PurchaseOrder $record) => $record->isReceivable() && Erp::can('stock.receive', $record->warehouse))
                ->modalWidth('4xl')
                ->fillForm(fn (PurchaseOrder $record) => ['lines' => $record->lines()->get()->map(fn ($l) => [
                    'purchase_order_line_id' => $l->id, 'item' => "{$l->sku} · {$l->name} ({$l->uom_code})", 'batched' => (bool) Product::find($l->product_id)?->track_batches, 'serialised' => (bool) Product::find($l->product_id)?->track_serials,
                    'qty' => Fields::qty(Qty::format(max(0, Qty::toMilli((string) $l->qty) - Qty::toMilli((string) $l->received_qty)))),
                ])->all()])
                ->schema(fn (PurchaseOrder $record) => [
                    TextInput::make('supplier_ref')->label('Supplier delivery note / invoice no.')->maxLength(60),
                    Repeater::make('lines')->columns(6)->addable(false)->deletable(false)->reorderable(false)->schema([
                        TextInput::make('item')->disabled()->dehydrated(false)->columnSpan(2),
                        TextInput::make('qty')->label('Received now')->numeric()->minValue(0)->required(),
                        Select::make('bin_id')->label('Into bin')->options(Fields::bins($record->warehouse_id))
                            ->visible($record->warehouse->use_bins)->placeholder('Receiving dock'),
                        TextInput::make('batch_no')->label('Batch no.')->maxLength(60)->visible(fn (Get $get) => (bool) $get('batched'))->required(fn (Get $get) => (bool) $get('batched')),
                        DatePicker::make('expires_on')->label('Expiry')->visible(fn (Get $get) => (bool) $get('batched')),
                        TagsInput::make('serials')->label('Serial / IMEI numbers (scan each)')->columnSpanFull()->visible(fn (Get $get) => (bool) $get('serialised')),
                        Hidden::make('purchase_order_line_id'),
                        Hidden::make('batched')->dehydrated(false),
                        Hidden::make('serialised')->dehydrated(false),
                    ]),
                    Textarea::make('note'),
                ])
                ->action(function (PurchaseOrder $record, array $data) {
                    $grn = Erp::run(fn ($actor) => app(GoodsReceiptService::class)->receive($actor, $record, $data));
                    $grn->status === 'held'
                        ? Notification::make()->warning()->title("{$grn->number} is waiting for a manager")->body('More than the over-delivery tolerance was received; nothing is in stock until a manager accepts it.')->persistent()->send()
                        : Notification::make()->success()->title("{$grn->number} posted")->body('The goods are in stock.')->send();
                }),
            Action::make('closeShort')->label('Close short')->icon(Heroicon::OutlinedArchiveBoxXMark)
                ->visible(fn (PurchaseOrder $record) => in_array($record->status, ['approved', 'partially_received'], true) && Erp::can('purchasing.manage', $record->warehouse))
                ->modalDescription('Nothing more is expected from the supplier; the rest of the order lapses.')
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(fn (PurchaseOrder $record, array $data) => Erp::run(fn ($actor) => $svc()->closeShort($actor, $record, $data['reason']), 'Closed')),
            Action::make('cancel')->label('Cancel order')->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->visible(fn (PurchaseOrder $record) => in_array($record->status, ['draft', 'pending_approval', 'approved'], true) && Erp::can('purchasing.manage', $record->warehouse))
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(fn (PurchaseOrder $record, array $data) => Erp::run(fn ($actor) => $svc()->cancel($actor, $record, $data['reason']), 'Cancelled')),
            Action::make('receipts')->label('Goods received')->icon(Heroicon::OutlinedDocumentText)->color('gray')
                ->visible(fn (PurchaseOrder $record) => $record->receipts()->exists())
                ->url(fn (PurchaseOrder $record) => GoodsReceiptResource::getUrl('index', ['tableSearch' => $record->number])),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Order')->searchable()->sortable(),
                TextColumn::make('supplier.name')->label('Supplier')->searchable(),
                TextColumn::make('warehouse.code')->label('To'),
                TextColumn::make('total_minor')->label('Total')->alignEnd()->formatStateUsing(fn ($state, PurchaseOrder $record) => self::money($state, $record)),
                TextColumn::make('expected_on')->label('Expected')->date()->placeholder('—')->sortable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => PurchaseOrder::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'pending_approval' => 'warning', 'approved', 'partially_received' => 'info', 'received' => 'success', 'cancelled', 'closed' => 'gray', default => null
                    }),
                TextColumn::make('created_at')->label('Created')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(PurchaseOrder::STATUSES)->multiple(),
                SelectFilter::make('supplier_id')->label('Supplier')->relationship('supplier', 'name'),
                SelectFilter::make('warehouse_id')->label('Warehouse')->options(fn () => Warehouse::orderBy('code')->pluck('code', 'id')),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->emptyStateHeading('No purchase orders yet');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseOrders::route('/'),
            'create' => CreatePurchaseOrder::route('/create'),
            'view' => ViewPurchaseOrder::route('/{record}'),
            'edit' => EditPurchaseOrder::route('/{record}/edit'),
        ];
    }
}
