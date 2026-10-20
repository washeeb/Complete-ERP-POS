<?php

namespace App\Filament\Resources\StockRequests;

use App\Domain\Inventory\StockRequestService;
use App\Filament\Resources\StockRequests\Pages\CreateStockRequest;
use App\Filament\Resources\StockRequests\Pages\ListStockRequests;
use App\Filament\Resources\StockRequests\Pages\ViewStockRequest;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\GlobalSearch;
use App\Models\Product;
use App\Models\StockRequest;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Stock → Stock requests: ask another branch for stock; between industries both sides approve first. */
class StockRequestResource extends Resource
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

    protected static ?string $model = StockRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?string $navigationLabel = 'Stock requests';

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('stock.request') || Erp::canAnywhere('stock.request.approve') || Erp::canAnywhere('stock.transfer');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('stock.request');
    }

    public static function canEdit(Model $record): bool
    {
        return false; // cancel and ask again instead
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = Erp::branchIds('stock.view');

        return parent::getEloquentQuery()->with('fromWarehouse', 'toWarehouse')
            ->when($ids !== null, fn ($q) => $q->where(fn ($q) => $q->whereIn('from_branch_id', $ids)->orWhereIn('to_branch_id', $ids)));
    }

    /** Requests waiting on the signed-in person's branches: to send, or to approve. */
    public static function getNavigationBadge(): ?string
    {
        $send = Erp::branchIds('stock.transfer');
        $approve = Erp::branchIds('stock.request.approve');
        $n = StockRequest::whereIn('status', ['requested', 'approved'])->get()->filter(fn (StockRequest $r) => match ($r->waitingFor()) {
            'supply' => $send === null || in_array($r->from_branch_id, $send, true),
            'supplier' => $approve === null || in_array($r->from_branch_id, $approve, true),
            'requester' => $approve === null || in_array($r->to_branch_id, $approve, true),
            default => false,
        })->count();

        return $n ? (string) $n : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(2)->schema([
                Select::make('to_warehouse_id')->label('For (your warehouse)')->required()->options(fn () => Fields::warehouses('stock.request')),
                Select::make('from_warehouse_id')->label('Ask')->required()->different('to_warehouse_id')
                    ->options(fn () => Warehouse::with('branch.industry')->where('is_active', true)->orderBy('code')->get()
                        ->mapWithKeys(fn ($w) => [$w->id => "{$w->code} · {$w->name}".($w->branch?->industry ? " ({$w->branch->industry->name})" : '')]))
                    ->helperText('A branch of another industry must approve, and so must a manager of yours, before anything is sent.'),
                Textarea::make('note')->label('Note (why, by when)')->columnSpanFull(),
            ]),
            Section::make('What you need')->description('Choose items as your branch sells them.')->schema([
                Repeater::make('lines')->hiddenLabel()->itemNumbers()->columns(7)->minItems(1)->addActionLabel('Add line')->schema([
                    Fields::productUnit()->live()->columnSpan(3),
                    TextInput::make('qty')->label('Quantity')->required()->numeric()->minValue(0.001),
                    Fields::available('../../to_warehouse_id')->label('You have')->columnSpan(1),
                    Fields::available('../../from_warehouse_id')->label('They have')->columnSpan(2),
                    Fields::duplicateWarning(),
                ]),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('Request'),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => StockRequest::STATUSES[$state] ?? $state),
                TextEntry::make('toWarehouse.name')->label('For'),
                TextEntry::make('fromWarehouse.name')->label('Asked of'),
                TextEntry::make('requested_by_name')->asPerson('requested_by')->label('Asked by')->belowContent(fn (StockRequest $record) => $record->created_at?->format('j M Y H:i')),
                TextEntry::make('cross_industry')->label('Between industries')->formatStateUsing(fn ($state) => $state ? 'Yes: both sides approve, moved at cost' : 'No'),
                TextEntry::make('requester_approved_by_name')->label('Approved (asking side)')->placeholder('—')
                    ->visible(fn (StockRequest $record) => $record->cross_industry),
                TextEntry::make('supplier_approved_by_name')->label('Approved (supplying side)')->placeholder('—')
                    ->visible(fn (StockRequest $record) => $record->cross_industry),
                TextEntry::make('supplied_by_name')->label('Sent by')->placeholder('—')->belowContent(fn (StockRequest $record) => $record->supplied_at?->format('j M Y H:i')),
                TextEntry::make('transfer.number')->label('Transfer')->placeholder('—')
                    ->url(fn (StockRequest $record) => $record->stock_transfer_id ? StockTransferResource::getUrl('view', ['record' => $record->stock_transfer_id]) : null),
                TextEntry::make('closed_reason')->label('Reason')->placeholder('—')->visible(fn (StockRequest $record) => in_array($record->status, ['rejected', 'cancelled'], true)),
                TextEntry::make('note')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Lines')->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('name')->columnSpan(2)->formatStateUsing(fn ($state, $record) => "{$record->sku} · {$state}"),
                    TextEntry::make('qty')->label('Asked for')->formatStateUsing(fn ($state, $record) => Fields::qty($state, $record->uom_code)),
                    TextEntry::make('supplied_qty')->label('Sent')->placeholder('—')->formatStateUsing(fn ($state, $record) => Fields::qty($state, $record->uom_code)),
                ]),
            ]),
        ]);
    }

    /** @return list<Action> */
    public static function requestActions(): array
    {
        $svc = fn () => app(StockRequestService::class);
        $canApprove = fn (StockRequest $r) => match ($r->waitingFor()) {
            'requester' => Erp::can('stock.request.approve', $r->toWarehouse),
            'supplier' => Erp::can('stock.request.approve', $r->fromWarehouse),
            default => false,
        };

        return [
            Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                ->visible($canApprove)->requiresConfirmation()
                ->modalDescription(fn (StockRequest $record) => $record->waitingFor() === 'requester'
                    ? 'Approves this request for your branch. The supplying branch approves next.'
                    : 'Approves sending these goods to another industry, at their cost.')
                ->action(fn (StockRequest $record) => Erp::run(fn ($actor) => $svc()->approve($actor, $record), 'Approved')),
            Action::make('supply')->label('Send')->icon(Heroicon::OutlinedTruck)->color('success')
                ->visible(fn (StockRequest $record) => $record->waitingFor() === 'supply' && Erp::can('stock.transfer', $record->fromWarehouse))
                ->modalDescription('Creates the transfer and takes the goods out of your warehouse now. Enter 0 for anything you cannot spare.')
                ->fillForm(fn (StockRequest $record) => ['lines' => $record->lines()->get()->map(fn ($l) => ['line_id' => $l->id,
                    'item' => "{$l->sku} · {$l->name} ({$l->uom_code})", 'qty' => Fields::qty($l->qty)])->all()])
                ->schema(fn (StockRequest $record) => [
                    Repeater::make('lines')->itemNumbers()->columns(4)->addable(false)->deletable(false)->reorderable(false)->schema([
                        TextInput::make('item')->label('Asked for')->disabled()->dehydrated(false)->columnSpan(2),
                        TextInput::make('qty')->label('Send (same unit)')->numeric()->minValue(0)->required(),
                        Select::make('send_product_id')->label('Your product')->searchable()->visible($record->cross_industry)
                            ->required($record->cross_industry)
                            ->getSearchResultsUsing(fn (string $search) => Product::where('is_active', true)->where('track_stock', true)
                                ->where(fn ($q) => $q->whereNull('industry_id')->orWhere('industry_id', $record->fromWarehouse->branch->industry_id))
                                ->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%"))
                                ->limit(30)->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->sku} · {$p->name}"]))
                            ->getOptionLabelUsing(fn ($value) => ($p = Product::find($value)) ? "{$p->sku} · {$p->name}" : null),
                        Hidden::make('line_id'),
                    ]),
                ])
                ->action(fn (StockRequest $record, array $data) => Erp::run(fn ($actor) => $svc()->supply($actor, $record,
                    collect($data['lines'])->mapWithKeys(fn ($l) => [$l['line_id'] => ['qty' => (string) $l['qty'], 'send_product_id' => $l['send_product_id'] ?? null]])->all()), 'Sent')),
            Action::make('reject')->label('Reject')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                ->visible(fn (StockRequest $record) => $record->waitingFor() !== null
                    && (Erp::can('stock.request.approve', $record->fromWarehouse) || Erp::can('stock.transfer', $record->fromWarehouse)
                        || ($record->waitingFor() === 'requester' && Erp::can('stock.request.approve', $record->toWarehouse))))
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(fn (StockRequest $record, array $data) => Erp::run(fn ($actor) => $svc()->reject($actor, $record, $data['reason']), 'Rejected')),
            Action::make('cancel')->label('Cancel request')->icon(Heroicon::OutlinedXCircle)->color('gray')
                ->visible(fn (StockRequest $record) => in_array($record->status, ['requested', 'approved'], true) && Erp::can('stock.request', $record->toWarehouse))
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(fn (StockRequest $record, array $data) => Erp::run(fn ($actor) => $svc()->cancel($actor, $record, $data['reason']), 'Cancelled')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Request')->searchable()->sortable(),
                TextColumn::make('toWarehouse.code')->label('For'),
                TextColumn::make('fromWarehouse.code')->label('Asked of'),
                IconColumn::make('cross_industry')->label('Between industries')->boolean()->falseIcon(null),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state, StockRequest $record) => match ($record->waitingFor()) {
                    'requester' => 'Awaiting approval (asking side)',
                    'supplier' => 'Awaiting approval (supplier)',
                    default => StockRequest::STATUSES[$state] ?? $state,
                })->color(fn ($state) => match ($state) {
                    'requested', 'approved' => 'warning', 'supplied' => 'info', 'received' => 'success', 'rejected' => 'danger', default => 'gray'
                }),
                TextColumn::make('requested_by_name')->asPerson('requested_by')->label('Asked by'),
                TextColumn::make('created_at')->label('Asked')->since()->sortable(),
            ])
            ->filters([SelectFilter::make('status')->options(StockRequest::STATUSES)])
            ->defaultSort('created_at', 'desc')
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockRequests::route('/'),
            'create' => CreateStockRequest::route('/create'),
            'view' => ViewStockRequest::route('/{record}'),
        ];
    }
}
