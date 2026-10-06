<?php

namespace App\Filament\Resources\SupplierPayments;

use App\Domain\Payables\SupplierPaymentService;
use App\Filament\Resources\SupplierPayments\Pages\ListSupplierPayments;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
                TextInput::make('amount')->required()->numeric()->minValue(0.01),
                TextInput::make('reference')->label('Transfer / MoMo / cheque reference')->maxLength(80),
                DatePicker::make('paid_on')->required()->default(now())->maxDate(now()),
                Select::make('branch_id')->label('Branch')->required()->options(fn () => FinanceUi::branches())->default(fn () => FinanceUi::hq()),
                TextInput::make('note')->maxLength(255),
            ])
            ->action(fn (array $data) => Erp::run(fn ($actor) => app(SupplierPaymentService::class)->pay($actor, $supplier ?? Supplier::findOrFail($data['supplier_id']),
                Branch::findOrFail($data['branch_id']), Account::findOrFail($data['account_id']), $data['method'], FinanceUi::toMinor($data['amount']),
                $data['reference'] ?? null, $data['paid_on'], null, $data['note'] ?? null), 'Payment recorded'));
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
                TextColumn::make('amount_minor')->label('Amount')->alignEnd()->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
                TextColumn::make('paid_by_name')->label('Recorded by'),
            ])
            ->filters([SelectFilter::make('method')->options(SupplierPayment::METHODS)])
            ->defaultSort('paid_on', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListSupplierPayments::route('/')];
    }
}
