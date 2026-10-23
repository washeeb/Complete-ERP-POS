<?php

namespace App\Filament\Resources\MomoCharges;

use App\Filament\Resources\MomoCharges\Pages\ListMomoCharges;
use App\Filament\Support\Erp;
use App\Models\MomoCharge;
use App\Models\SalePayment;
use App\Support\Money;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** System → Mobile money: the payment provider, and every payment request sent from a till (owner request 2026-10-23). */
class MomoChargeResource extends Resource
{
    protected static ?string $model = MomoCharge::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Mobile money';

    protected static ?string $modelLabel = 'mobile money request';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('payments.momo.manage') || Erp::canAnywhere('cash.view');
    }

    public static function canCreate(): bool
    {
        return false; // sent from the till
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
        $ids = Erp::canAnywhere('payments.momo.manage') ? Erp::branchIds('payments.momo.manage') : Erp::branchIds('cash.view');

        return parent::getEloquentQuery()->with(['branch', 'device', 'sale'])->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('j M H:i')->sortable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => MomoCharge::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'success' => 'success', 'failed', 'expired' => 'danger', default => 'warning'
                    })
                    ->description(fn (MomoCharge $r) => $r->status !== 'success' ? $r->message : null),
                TextColumn::make('amount_minor')->label('Amount')->formatStateUsing(fn ($state, MomoCharge $r) => Money::display((int) $state, $r->currency_code)),
                TextColumn::make('network')->formatStateUsing(fn ($state) => SalePayment::MOMO_PROVIDERS[$state] ?? $state),
                TextColumn::make('phone')->label('Customer number'),
                TextColumn::make('sale.receipt_no')->label('Sale')->placeholder(fn (MomoCharge $r) => $r->status === 'success' ? 'Paid, not on a sale' : '—'),
                TextColumn::make('provider_ref')->label('Transaction')->placeholder('—')->copyable(),
                TextColumn::make('branch.name')->label('Branch')->toggleable(),
                TextColumn::make('device.name')->label('Till')->toggleable(),
                TextColumn::make('requested_by_name')->label('Cashier')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(MomoCharge::STATUSES),
                // Money received with no sale: the till gave up before the customer approved — give it back or ring up the sale.
                Filter::make('unused')->label('Paid, not on a sale')->query(fn (Builder $q) => $q->where('status', 'success')->whereNull('sale_id')),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListMomoCharges::route('/')];
    }
}
