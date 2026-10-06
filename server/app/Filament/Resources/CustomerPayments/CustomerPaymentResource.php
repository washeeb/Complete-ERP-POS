<?php

namespace App\Filament\Resources\CustomerPayments;

use App\Domain\Sales\ReceivablesService;
use App\Filament\Resources\CustomerPayments\Pages\ListCustomerPayments;
use App\Filament\Support\Erp;
use App\Models\CustomerPayment;
use App\Models\SalePayment;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Payments received on customer accounts (recorded from the customer page). */
class CustomerPaymentResource extends Resource
{
    protected static ?string $model = CustomerPayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Payments received';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('receivables.view') || Erp::canAnywhere('receivables.collect');
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
        $a = Erp::branchIds('receivables.view');
        $b = Erp::branchIds('receivables.collect');
        $ids = $a === null || $b === null ? null : array_values(array_unique([...$a, ...$b]));

        return parent::getEloquentQuery()->with('customer')->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Receipt')->searchable(),
                TextColumn::make('received_on')->label('Date')->date()->sortable(),
                TextColumn::make('customer.name')->label('Customer')->searchable(),
                TextColumn::make('method')->formatStateUsing(fn ($state, CustomerPayment $record) => (ReceivablesService::METHODS[$state] ?? $state)
                    .($record->provider ? ' · '.(SalePayment::MOMO_PROVIDERS[$record->provider] ?? $record->provider) : '')),
                TextColumn::make('reference')->placeholder('—')->searchable(),
                TextColumn::make('amount_minor')->label('Amount')->alignEnd()->formatStateUsing(fn ($state, CustomerPayment $record) => Money::display($state, $record->currency_code)),
                TextColumn::make('received_by_name')->label('Received by'),
            ])
            ->filters([SelectFilter::make('method')->options(ReceivablesService::METHODS)])
            ->defaultSort('received_on', 'desc')
            ->recordActions([
                Action::make('print')->label('Receipt')->icon(Heroicon::OutlinedPrinter)->color('gray')
                    ->url(fn (CustomerPayment $record) => route('print.payment', $record), shouldOpenInNewTab: true),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCustomerPayments::route('/')];
    }
}
