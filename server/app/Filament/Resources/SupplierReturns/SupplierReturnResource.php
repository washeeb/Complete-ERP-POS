<?php

namespace App\Filament\Resources\SupplierReturns;

use App\Domain\Inventory\ExpiryService;
use App\Filament\Resources\SupplierReturns\Pages\ListSupplierReturns;
use App\Filament\Resources\SupplierReturns\Pages\ViewSupplierReturn;
use App\Filament\Resources\Waybills\WaybillResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\GlobalSearch;
use App\Models\SupplierReturn;
use App\Models\Waybill;
use App\Support\Qty;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
use Illuminate\Support\HtmlString;
use UnitEnum;

/** Returns to suppliers: prepared from Expiry & returns, approved and sent by someone else, then settled. */
class SupplierReturnResource extends Resource
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

    protected static ?string $model = SupplierReturn::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?string $navigationLabel = 'Returns to suppliers';

    protected static ?int $navigationSort = 8;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('stock.expiry') || Erp::canAnywhere('stock.adjust.approve') || Erp::canAnywhere('payables.view');
    }

    public static function canCreate(): bool
    {
        return false; // prepared from Stock → Expiry & returns
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
        $ids = Erp::branchIds('stock.view');

        return parent::getEloquentQuery()->with('warehouse', 'supplier')->withCount('lines')->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('Return'),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => SupplierReturn::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['posted' => 'success', 'submitted' => 'warning'][$state] ?? 'gray'),
                TextEntry::make('supplier.name')->label('Supplier'),
                TextEntry::make('warehouse.name')->label('From store'),
                TextEntry::make('reason')->formatStateUsing(fn ($state) => SupplierReturn::REASONS[$state] ?? $state),
                TextEntry::make('settlement')->label('The supplier gives')->formatStateUsing(fn ($state) => SupplierReturn::SETTLEMENTS[$state] ?? $state),
                TextEntry::make('total_minor')->label('Value at cost')->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
                TextEntry::make('prepared_by_name')->asPerson('prepared_by')->label('Prepared by')->belowContent(fn (SupplierReturn $r) => $r->created_at?->format('j M Y H:i')),
                TextEntry::make('approved_by_name')->asPerson('approved_by')->label('Approved and sent by')->placeholder('—')
                    ->belowContent(fn (SupplierReturn $r) => $r->posted_at?->format('j M Y H:i')),
                TextEntry::make('settlement_ref')->label('Supplier\'s reference')->placeholder('not settled yet')
                    ->belowContent(fn (SupplierReturn $r) => $r->settled_at ? "{$r->settled_by_name}, {$r->settled_at->format('j M Y')}" : null),
                TextEntry::make('refund_minor')->label('Refund received')->visible(fn (SupplierReturn $r) => $r->settlement === 'refund')
                    ->placeholder('—')->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
                TextEntry::make('note')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Lines')->schema([
                TextEntry::make('lines_table')->hiddenLabel()->html()->state(fn (SupplierReturn $r) => new HtmlString(self::linesHtml($r))),
            ]),
        ]);
    }

    private static function linesHtml(SupplierReturn $r): string
    {
        $cell = fn ($v, $num = false) => '<td style="padding:4px 6px;border-top:1px solid color-mix(in srgb, currentColor 6%, transparent)'.($num ? ';text-align:right' : '').'">'.e($v).'</td>';
        $rows = $r->lines()->with('product.baseUom')->get()->map(fn ($l) => '<tr>'.$cell($l->line_no).$cell($l->product->sku).$cell($l->product->name).$cell($l->batch_no ?? '')
            .$cell($l->expires_on?->format('j M Y') ?? '').$cell(Fields::qty(Qty::format(Qty::toMilli((string) $l->qty)), $l->product->baseUom?->code), true)
            .$cell(FinanceUi::money((int) $l->value_minor), true).'</tr>')->implode('');

        return '<table style="width:100%;font-size:.85rem;border-collapse:collapse"><tr><th style="text-align:left">#</th><th style="text-align:left">SKU</th>'
            .'<th style="text-align:left">Product</th><th style="text-align:left">Batch</th><th style="text-align:left">Expires</th><th style="text-align:right">Quantity</th>'
            .'<th style="text-align:right">Value (cost)</th></tr>'.$rows.'</table>';
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('created_at')->label('Date')->date('j M Y'),
                TextColumn::make('supplier.name')->label('Supplier')->searchable(),
                TextColumn::make('warehouse.code')->label('Store'),
                TextColumn::make('reason')->formatStateUsing(fn ($state) => SupplierReturn::REASONS[$state] ?? $state),
                TextColumn::make('settlement')->label('Settlement')->formatStateUsing(fn ($state) => SupplierReturn::SETTLEMENTS[$state] ?? $state)
                    ->description(fn (SupplierReturn $r) => $r->status === 'posted' ? ($r->settled_at ? 'settled: '.$r->settlement_ref : 'waiting for the supplier') : null),
                TextColumn::make('lines_count')->label('Lines'),
                TextColumn::make('total_minor')->label('Value')->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => SupplierReturn::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['posted' => 'success', 'submitted' => 'warning'][$state] ?? 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')->options(SupplierReturn::STATUSES),
                SelectFilter::make('settlement')->options(SupplierReturn::SETTLEMENTS),
                SelectFilter::make('supplier_id')->label('Supplier')->relationship('supplier', 'name')->searchable(),
            ]);
    }

    /** Approve and send, record the supplier's settlement, cancel, print the return note. */
    public static function returnActions(): array
    {
        $svc = fn () => app(ExpiryService::class);

        return [
            Action::make('post')->label('Approve and send')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                ->visible(fn (SupplierReturn $record) => $record->status === 'submitted')->requiresConfirmation()
                ->modalDescription('The stock leaves the store now, at average cost. With a credit note or replacement, what we owe the supplier goes down now; '
                    .'with no credit, the value is written off as a stock loss. You cannot approve a return you prepared.')
                ->action(fn (SupplierReturn $record) => Erp::run(fn ($actor) => $svc()->postReturn($actor, $record), 'Approved — the stock has left')),
            Action::make('settle')->label('Record the supplier\'s settlement')->icon(Heroicon::OutlinedReceiptRefund)->color('primary')
                ->visible(fn (SupplierReturn $record) => $record->status === 'posted' && ! $record->settled_at)
                ->schema(fn (SupplierReturn $record) => array_values(array_filter([
                    TextInput::make('reference')->label(match ($record->settlement) {
                        'credit_note' => 'Supplier\'s credit note number', 'replacement' => 'Delivery note of the replacement stock',
                        'refund' => 'Refund receipt or MoMo / bank reference', default => 'Supplier\'s acknowledgement or collection note'
                    })->required()->maxLength(120),
                    $record->settlement === 'refund' ? TextInput::make('refund')->label('Amount refunded')->numeric()->minValue(0)->required()
                        ->default(fn () => $record->total_minor / 100)->helperText('Less than the value at cost: the difference is booked as a stock loss.') : null,
                    $record->settlement === 'refund' ? Select::make('account_id')->label('Received into')->options(fn () => FinanceUi::moneyAccounts())->required() : null,
                ])))
                ->modalDescription(fn (SupplierReturn $record) => $record->settlement === 'replacement'
                    ? 'Receive the replacement stock as a normal delivery (goods received note) at no cost, or with the supplier\'s bill, then record its reference here.' : null)
                ->action(fn (SupplierReturn $record, array $data) => Erp::run(fn ($actor) => $svc()->settle($actor, $record, [
                    'reference' => $data['reference'], 'refund' => isset($data['refund']) ? FinanceUi::toMinor($data['refund']) : null, 'account_id' => $data['account_id'] ?? null]),
                    'Settlement recorded')),
            Action::make('cancel')->label('Cancel')->color('danger')->visible(fn (SupplierReturn $record) => $record->status === 'submitted')
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(fn (SupplierReturn $record, array $data) => Erp::run(fn ($actor) => $svc()->cancelReturn($actor, $record, $data['reason']), 'Cancelled')),
            Action::make('waybill')->label('Waybill')->icon('heroicon-o-truck')->color('gray')
                ->url(fn (SupplierReturn $record) => ($w = Waybill::where('source_id', $record->id)->where('status', '<>', 'cancelled')->first())
                    ? WaybillResource::getUrl('view', ['record' => $w])
                    : WaybillResource::getUrl('create', ['kind' => 'supplier_return', 'source' => $record->id])),
            Action::make('print')->label('Return note (print)')->icon(Heroicon::OutlinedPrinter)->color('gray')
                ->url(fn (SupplierReturn $record) => route('print.supplier-return', $record), true),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListSupplierReturns::route('/'), 'view' => ViewSupplierReturn::route('/{record}')];
    }
}
