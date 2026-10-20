<?php

namespace App\Filament\Resources\PurchaseRequests;

use App\Domain\Purchasing\PurchaseRequestService;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\PurchaseRequests\Pages\CreatePurchaseRequest;
use App\Filament\Resources\PurchaseRequests\Pages\ListPurchaseRequests;
use App\Filament\Resources\PurchaseRequests\Pages\ViewPurchaseRequest;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** What a store needs, asked by the stock controller; a buyer orders it (a draft purchase order) or rejects it. */
class PurchaseRequestResource extends Resource
{
    protected static ?string $model = PurchaseRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?string $navigationLabel = 'Purchase requests';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('purchasing.request') || Erp::canAnywhere('purchasing.manage');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('purchasing.request');
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
        $ids = [];
        foreach (['purchasing.request', 'purchasing.manage'] as $p) {
            $b = Erp::branchIds($p);
            if ($b === null) {
                $ids = null;
                break;
            }
            $ids = array_merge($ids, $b);
        }

        return parent::getEloquentQuery()->with('warehouse')->withCount('lines')->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(3)->schema([
                Select::make('warehouse_id')->label('For the store')->required()->live()->options(fn () => Fields::warehouses('purchasing.request')),
                DatePicker::make('needed_by')->label('Needed by (optional)')->minDate(today()),
                Textarea::make('note')->rows(2)->columnSpanFull(),
            ]),
            Section::make('What to order')->schema([
                Repeater::make('lines')->hiddenLabel()->itemNumbers()->columns(7)->minItems(1)->addActionLabel('Add line')->schema([
                    Fields::productUnit()->live()->columnSpan(3),
                    TextInput::make('qty')->label('Quantity')->required()->numeric()->minValue(0.001),
                    Fields::available('../../warehouse_id')->label('In stock now'),
                    TextInput::make('note')->label('Note (optional)')->maxLength(255)->columnSpan(2),
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
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => PurchaseRequest::STATUSES[$state] ?? $state),
                TextEntry::make('warehouse.name')->label('For the store'),
                TextEntry::make('needed_by')->label('Needed by')->date()->placeholder('—'),
                TextEntry::make('requested_by_name')->label('Asked by'),
                TextEntry::make('created_at')->label('Asked on')->dateTime(),
                TextEntry::make('purchaseOrder.number')->label('Purchase order')->placeholder('—')
                    ->url(fn (PurchaseRequest $record) => $record->purchase_order_id ? PurchaseOrderResource::getUrl('view', ['record' => $record->purchase_order_id]) : null),
                TextEntry::make('closed_reason')->label('Reason')->placeholder('—'),
                TextEntry::make('note')->columnSpanFull()->placeholder('—'),
            ]),
            RepeatableEntry::make('lines')->columns(4)->schema([
                TextEntry::make('line_no')->label('#'),
                TextEntry::make('name')->label('Product')->formatStateUsing(fn ($state, $record) => "{$record->sku} · {$state}"),
                TextEntry::make('qty')->label('Quantity')->formatStateUsing(fn ($state, $record) => Fields::qty((string) $state, $record->uom_code)),
                TextEntry::make('note')->placeholder('—'),
            ]),
        ]);
    }

    /** Order (a buyer chooses the supplier: a draft purchase order), Reject (a buyer), Cancel (the person who asked). */
    public static function workflowActions(): array
    {
        $svc = fn () => app(PurchaseRequestService::class);

        return [
            Action::make('order')->label('Order')->icon(Heroicon::OutlinedShoppingCart)->color('primary')
                ->visible(fn (PurchaseRequest $record) => $record->status === 'requested' && Erp::can('purchasing.manage', $record->warehouse))
                ->modalDescription("Makes a draft purchase order to this supplier with the requested lines, priced from the supplier's list. Check it, then submit it as usual.")
                ->schema([Select::make('supplier_id')->label('Supplier')->required()->searchable()
                    ->options(fn () => Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id'))])
                ->action(function (PurchaseRequest $record, array $data, Action $action) use ($svc) {
                    $po = Erp::run(fn ($actor) => $svc()->order($actor, $record, Supplier::findOrFail($data['supplier_id'])), 'Draft purchase order made');
                    $action->redirect(PurchaseOrderResource::getUrl('edit', ['record' => $po]));
                }),
            Action::make('reject')->label('Reject')->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->visible(fn (PurchaseRequest $record) => $record->status === 'requested' && Erp::can('purchasing.manage', $record->warehouse))
                ->schema([TextInput::make('reason')->label('Why (the person who asked sees it)')->required()->maxLength(200)])
                ->action(fn (PurchaseRequest $record, array $data) => Erp::run(fn ($actor) => $svc()->reject($actor, $record, $data['reason']), 'Rejected')),
            Action::make('cancel')->label('Cancel')->icon(Heroicon::OutlinedNoSymbol)->color('gray')
                ->visible(fn (PurchaseRequest $record) => $record->status === 'requested' && $record->requested_by === Erp::user()?->id)
                ->schema([TextInput::make('reason')->label('Why')->required()->maxLength(200)])
                ->action(fn (PurchaseRequest $record, array $data) => Erp::run(fn ($actor) => $svc()->cancel($actor, $record, $data['reason']), 'Cancelled')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Request')->searchable()->sortable(),
                TextColumn::make('created_at')->label('Asked on')->date()->sortable(),
                TextColumn::make('warehouse.name')->label('Store'),
                TextColumn::make('lines_count')->label('Lines'),
                TextColumn::make('needed_by')->label('Needed by')->date()->placeholder('—'),
                TextColumn::make('requested_by_name')->label('Asked by'),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => PurchaseRequest::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'requested' => 'warning', 'ordered' => 'success', 'rejected' => 'danger', default => 'gray',
                    }),
            ])
            ->filters([SelectFilter::make('status')->options(PurchaseRequest::STATUSES)])
            ->recordActions([ViewAction::make(), ...self::workflowActions()])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListPurchaseRequests::route('/'), 'create' => CreatePurchaseRequest::route('/create'), 'view' => ViewPurchaseRequest::route('/{record}')];
    }
}
