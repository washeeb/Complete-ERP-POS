<?php

namespace App\Filament\Resources\SupplierPayments;

use App\Domain\Payables\SupplierLedger;
use App\Domain\Payables\SupplierPaymentService;
use App\Domain\Payables\WithholdingTax;
use App\Filament\Resources\SupplierPayments\Pages\ListSupplierPayments;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\GlobalSearch;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Money paid to suppliers (settles the oldest bills first). */
class SupplierPaymentResource extends Resource
{
    use GlobalSearch;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'reference', 'supplier.name'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return static::searchDetails(['Supplier' => $record->supplier?->name, 'Reference' => $record->reference]);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['supplier']);
    }

    protected static ?string $model = SupplierPayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Supplier payments';

    protected static ?int $navigationSort = 11;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('payables.view') && config('erp.node.role') !== 'branch';
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('supplier', 'account');
    }

    public static function payAction(?Supplier $supplier = null): Action
    {
        return Action::make('pay')->label('Pay a supplier')->icon(Heroicon::OutlinedBanknotes)->color('success')
            ->visible(fn () => Erp::canAnywhere('payables.pay') && config('erp.node.role') !== 'branch')
            ->schema([
                Select::make('supplier_id')->label('Supplier')->required()->searchable()->default($supplier?->id)
                    ->options(fn () => Supplier::orderBy('name')->pluck('name', 'id'))->hidden($supplier !== null),
                Select::make('account_id')->label('Paid from')->required()->options(fn () => FinanceUi::moneyAccounts())
                    ->default(fn () => Account::where('system_key', 'bank')->value('id')),
                Select::make('method')->required()->options(SupplierPayment::METHODS)->default('bank'),
                TextInput::make('amount')->label(fn () => app(WithholdingTax::class)->enabled(Erp::user()->tenant_id) ? 'Amount to settle (before withholding tax)' : 'Amount')
                    ->required()->numeric()->minValue(0.01)->live(onBlur: true),
                // Withholding tax: worked out on the bills this settles (VAT left out); the cash to pay is the rest.
                Toggle::make('withhold')->label('Keep back withholding tax')->default(true)->live()
                    ->visible(fn () => app(WithholdingTax::class)->enabled(Erp::user()->tenant_id)),
                Placeholder::make('wht_preview')->label('Withholding tax')
                    ->visible(fn () => app(WithholdingTax::class)->enabled(Erp::user()->tenant_id))
                    ->content(fn ($get) => self::whtPreview($supplier?->id ?? $get('supplier_id'), $get('amount'), (bool) $get('withhold'), $get('wht'))),
                TextInput::make('wht')->label('Withholding tax to keep back, if different')->numeric()->minValue(0)->live(onBlur: true)
                    ->helperText('Leave empty to use the figure above (your accountant may give another).')
                    ->visible(fn ($get) => app(WithholdingTax::class)->enabled(Erp::user()->tenant_id) && $get('withhold')),
                TextInput::make('reference')->label('Transfer / MoMo / cheque reference')->maxLength(80),
                DatePicker::make('paid_on')->required()->default(now())->maxDate(now()),
                Select::make('branch_id')->label('Branch')->required()->options(fn () => FinanceUi::branches())->default(fn () => FinanceUi::hq()),
                TextInput::make('note')->maxLength(255),
            ])
            ->action(fn (array $data) => Erp::run(fn ($actor) => app(SupplierPaymentService::class)->pay($actor, $supplier ?? Supplier::findOrFail($data['supplier_id']),
                Branch::findOrFail($data['branch_id']), Account::findOrFail($data['account_id']), $data['method'], FinanceUi::toMinor($data['amount']),
                $data['reference'] ?? null, $data['paid_on'], null, $data['note'] ?? null, (bool) ($data['withhold'] ?? true),
                filled($data['wht'] ?? null) ? FinanceUi::toMinor($data['wht']) : null), 'Payment recorded'));
    }

    /** "GHS 30.00 (3 % of GHS 1,000.00 before VAT) · pay GHS 1,120.00" for the form, before saving. */
    private static function whtPreview(?string $supplierId, mixed $amount, bool $withhold, mixed $override): string
    {
        $supplier = $supplierId ? Supplier::find($supplierId) : null;
        if (! $supplier || ! is_numeric($amount) || (float) $amount <= 0) {
            return 'Choose the supplier and the amount.';
        }
        $gross = FinanceUi::toMinor($amount);
        if (! $withhold) {
            return 'None kept back. Pay '.FinanceUi::money($gross).'.';
        }
        $w = app(WithholdingTax::class)->compute($supplier, $gross, app(SupplierLedger::class)->plan($supplier->id, $gross));
        $wht = filled($override) && is_numeric($override) ? FinanceUi::toMinor($override) : $w['wht'];

        return ($w['why'] && ! filled($override) ? $w['why'].' ' : FinanceUi::money($wht).($w['rate_bp'] ? ' ('.($w['rate_bp'] / 100).' % of '.FinanceUi::money($w['base']).' before VAT)' : '').'. ')
            .'Pay the supplier '.FinanceUi::money($gross - $wht).'.';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('paid_on')->label('Date')->date()->sortable(),
                TextColumn::make('supplier.name')->label('Supplier')->searchable(),
                TextColumn::make('method')->formatStateUsing(fn ($state) => SupplierPayment::METHODS[$state] ?? $state),
                TextColumn::make('account.name')->label('Paid from'),
                TextColumn::make('reference')->placeholder('—')->searchable(),
                TextColumn::make('amount_minor')->label('Settles')->alignEnd()->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
                TextColumn::make('wht_minor')->label('Withholding tax')->alignEnd()->formatStateUsing(fn ($state) => (int) $state ? FinanceUi::money((int) $state) : '—')
                    ->description(fn (SupplierPayment $r) => $r->wht_certificate_no),
                TextColumn::make('paid')->label('Paid out')->alignEnd()->state(fn (SupplierPayment $r) => FinanceUi::money($r->cashMinor())),
                TextColumn::make('paid_by_name')->label('Recorded by'),
            ])
            ->filters([SelectFilter::make('method')->options(SupplierPayment::METHODS)])
            ->recordActions([
                Action::make('certificate')->label('WHT certificate')->icon(Heroicon::OutlinedDocumentText)->color('gray')
                    ->visible(fn (SupplierPayment $r) => $r->wht_minor > 0)->url(fn (SupplierPayment $r) => route('print.wht', $r), shouldOpenInNewTab: true),
            ])
            ->defaultSort('paid_on', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListSupplierPayments::route('/')];
    }
}
