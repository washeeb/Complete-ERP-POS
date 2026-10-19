<?php

namespace App\Filament\Resources\SupplierBills;

use App\Domain\Payables\BillService;
use App\Filament\Resources\SupplierBills\Pages\CreateSupplierBill;
use App\Filament\Resources\SupplierBills\Pages\ListSupplierBills;
use App\Filament\Resources\SupplierBills\Pages\ViewSupplierBill;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\GlobalSearch;
use App\Filament\Support\QuickCreate;
use App\Models\GoodsReceipt;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\TaxGroup;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Supplier invoices, matched to goods received (3-way match) or charged to expense accounts. */
class SupplierBillResource extends Resource
{
    use GlobalSearch;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'supplier_invoice_no', 'supplier.name'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return static::searchDetails(['Supplier' => $record->supplier?->name, 'Their invoice' => $record->supplier_invoice_no]);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['supplier']);
    }

    protected static ?string $model = SupplierBill::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Supplier bills';

    protected static ?string $modelLabel = 'supplier bill';

    protected static ?int $navigationSort = 10;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('payables.view') && config('erp.node.role') !== 'branch';
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('payables.manage') && config('erp.node.role') !== 'branch';
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
        return parent::getEloquentQuery()->with('supplier');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = SupplierBill::where('status', 'pending_approval')->count();

        return $n ? (string) $n : null;
    }

    /** "GRN-HQ-000001 · Indomie noodles — 3 CTN to bill" → billable line data, for one supplier. */
    public static function billableLines(?string $supplierId, ?array $grnIds = null): array
    {
        if (! $supplierId) {
            return [];
        }
        $out = [];
        $q = GoodsReceipt::where('supplier_id', $supplierId)->where('status', 'posted')->when($grnIds, fn ($q) => $q->whereIn('id', $grnIds))->orderBy('number');
        foreach ($q->get() as $grn) {
            foreach (app(BillService::class)->billable($grn) as $l) {
                $out[$l['goods_receipt_line_id']] = $l + ['label' => "{$grn->number} · {$l['name']} — ".Fields::qty($l['open_qty'], $l['uom']).' to bill'];
            }
        }

        return $out;
    }

    public static function form(Schema $schema): Schema
    {
        $currency = fn () => FinanceUi::currency();

        return $schema->columns(1)->components([
            Section::make()->columns(3)->schema([
                QuickCreate::on(Select::make('supplier_id')->label('Supplier')->required()->searchable()->live()
                    ->options(fn () => Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->afterStateUpdated(fn (Set $set) => $set('stock_lines', [])), 'supplier'),
                TextInput::make('supplier_invoice_no')->label('Supplier’s invoice number')->required()->maxLength(60),
                Select::make('branch_id')->label('Branch')->required()->options(fn () => FinanceUi::branches())->default(fn () => FinanceUi::hq()),
                DatePicker::make('bill_date')->label('Invoice date')->required()->default(now())->maxDate(now()),
                DatePicker::make('due_on')->label('Due')->helperText('Blank = the supplier’s payment terms.'),
                Textarea::make('note')->columnSpanFull(),
            ]),
            Section::make('Goods received')->description('Bill only what was received. Prices are before tax; the order price is filled in — change it to what the supplier charged.')->schema([
                Select::make('grns')->label('Add everything still to bill from')->multiple()->live()
                    ->options(fn (Get $get) => $get('supplier_id') ? GoodsReceipt::where('supplier_id', $get('supplier_id'))->where('status', 'posted')->orderBy('number')->pluck('number', 'id') : [])
                    ->afterStateUpdated(fn ($state, Get $get, Set $set) => $set('stock_lines', array_values(array_map(fn ($l) => ['goods_receipt_line_id' => $l['goods_receipt_line_id'],
                        'qty' => Fields::qty($l['open_qty']), 'unit_cost' => Money::format($l['order_cost_minor'], $currency())], self::billableLines($get('supplier_id'), $state ?: []))))),
                Repeater::make('stock_lines')->hiddenLabel()->columns(4)->addActionLabel('Add a received line')->defaultItems(0)->schema([
                    Select::make('goods_receipt_line_id')->label('Received')->required()->columnSpan(2)->live()
                        ->options(fn (Get $get) => array_map(fn ($l) => $l['label'], self::billableLines($get('../../supplier_id'))))
                        ->afterStateUpdated(function ($state, Get $get, Set $set) use ($currency) {
                            $l = self::billableLines($get('../../supplier_id'))[$state] ?? null;
                            if ($l) {
                                $set('qty', Fields::qty($l['open_qty']));
                                $set('unit_cost', Money::format($l['order_cost_minor'], $currency()));
                            }
                        }),
                    TextInput::make('qty')->label('Quantity')->required()->numeric()->minValue(0.001),
                    TextInput::make('unit_cost')->label('Price per unit (before tax)')->required()->numeric()->minValue(0),
                ]),
            ]),
            Section::make('Other charges')->description('Rent, electricity, transport… charged straight to an expense account.')->collapsed()->schema([
                Repeater::make('expense_lines')->hiddenLabel()->columns(4)->addActionLabel('Add a charge')->defaultItems(0)->schema([
                    Select::make('account_id')->label('Account')->required()->searchable()->options(fn () => FinanceUi::accounts(withControl: false, types: ['expense', 'asset'])),
                    TextInput::make('description')->maxLength(255),
                    TextInput::make('amount')->label('Amount (before tax)')->required()->numeric()->minValue(0.01),
                    Select::make('tax_group_id')->label('Tax')->options(fn () => TaxGroup::orderBy('code')->pluck('name', 'id'))->placeholder('No tax'),
                ]),
            ]),
            Hidden::make('grns_seen'),
        ]);
    }

    public static function toDomain(array $data): array
    {
        $lines = [];
        foreach ($data['stock_lines'] ?? [] as $l) {
            $lines[] = ['goods_receipt_line_id' => $l['goods_receipt_line_id'], 'qty' => (string) $l['qty'], 'unit_cost_minor' => FinanceUi::toMinor($l['unit_cost'])];
        }
        foreach ($data['expense_lines'] ?? [] as $l) {
            $lines[] = ['account_id' => $l['account_id'], 'description' => $l['description'] ?? null, 'amount_minor' => FinanceUi::toMinor($l['amount']),
                'tax_group_id' => $l['tax_group_id'] ?? null];
        }

        return ['supplier_invoice_no' => $data['supplier_invoice_no'], 'bill_date' => $data['bill_date'], 'due_on' => $data['due_on'] ?? null,
            'note' => $data['note'] ?? null, 'lines' => $lines];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('supplier.name')->label('Supplier')->searchable(),
                TextColumn::make('supplier_invoice_no')->label('Their invoice')->searchable(),
                TextColumn::make('bill_date')->label('Date')->date()->sortable(),
                TextColumn::make('due_on')->label('Due')->date()->sortable(),
                TextColumn::make('total_minor')->label('Total')->alignEnd()->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
                TextColumn::make('variance_minor')->label('vs order')->alignEnd()->formatStateUsing(fn ($state) => $state ? FinanceUi::money((int) $state) : '—')
                    ->color(fn ($state) => $state ? 'warning' : null),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => SupplierBill::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'posted' => 'success', 'rejected' => 'danger', default => 'warning'
                    }),
            ])
            ->filters([SelectFilter::make('status')->options(SupplierBill::STATUSES)])
            ->defaultSort('number', 'desc')
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $m = fn ($state) => FinanceUi::money((int) $state);

        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => SupplierBill::STATUSES[$state] ?? $state),
                TextEntry::make('supplier.name')->label('Supplier'),
                TextEntry::make('supplier_invoice_no')->label('Their invoice'),
                TextEntry::make('bill_date')->label('Date')->date(),
                TextEntry::make('due_on')->label('Due')->date(),
                TextEntry::make('net_minor')->label('Before tax')->formatStateUsing($m),
                TextEntry::make('tax_minor')->label('Tax')->formatStateUsing($m),
                TextEntry::make('total_minor')->label('Total')->weight('bold')->formatStateUsing($m),
                TextEntry::make('variance_minor')->label('Difference from order prices')->formatStateUsing($m)->color(fn ($state) => $state ? 'warning' : null),
                TextEntry::make('created_by_name')->asPerson('created_by')->label('Entered by'),
                TextEntry::make('approved_by_name')->label('Approved / rejected by')->placeholder('—'),
                TextEntry::make('variance_reason')->label('Reason')->placeholder('—')->columnSpanFull(),
                TextEntry::make('note')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Lines')->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->columns(6)->schema([
                    TextEntry::make('description')->columnSpan(2)->formatStateUsing(fn ($state, $record) => $record->account ? $record->account->label().' · '.$state : $state),
                    TextEntry::make('qty')->formatStateUsing(fn ($state) => Fields::qty($state)),
                    TextEntry::make('unit_cost_minor')->label('Price')->formatStateUsing(fn ($state, $record) => FinanceUi::money((int) $state)
                        .($record->order_cost_minor !== null && $record->order_cost_minor !== $record->unit_cost_minor ? ' (order '.FinanceUi::money($record->order_cost_minor).')' : '')),
                    TextEntry::make('tax_minor')->label('Tax')->formatStateUsing($m),
                    TextEntry::make('total_minor')->label('Total')->formatStateUsing($m),
                ]),
            ]),
        ]);
    }

    public static function approvalActions(): array
    {
        $visible = fn (SupplierBill $record) => $record->status === 'pending_approval' && Erp::canAnywhere('payables.approve') && $record->created_by !== Erp::user()->id;

        return [
            Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheck)->color('success')->visible($visible)
                ->schema([TextInput::make('reason')->label('Why the difference is accepted')->maxLength(255)])
                ->action(fn (SupplierBill $record, array $data) => Erp::run(fn ($actor) => app(BillService::class)->approve($actor, $record, $data['reason'] ?? null), 'Bill approved and posted')),
            Action::make('reject')->label('Reject')->icon(Heroicon::OutlinedXMark)->color('danger')->visible($visible)
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(fn (SupplierBill $record, array $data) => Erp::run(fn ($actor) => app(BillService::class)->reject($actor, $record, $data['reason']), 'Bill rejected')),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListSupplierBills::route('/'), 'create' => CreateSupplierBill::route('/create'), 'view' => ViewSupplierBill::route('/{record}')];
    }
}
