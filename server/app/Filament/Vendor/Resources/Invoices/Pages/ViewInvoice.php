<?php

namespace App\Filament\Vendor\Resources\Invoices\Pages;

use App\Domain\Licensing\Vendor\VendorBilling;
use App\Filament\Vendor\Resources\Invoices\InvoiceResource;
use App\Filament\Vendor\VendorUi;
use App\Models\Vendor\VendorInvoice;
use App\Models\Vendor\VendorPayment;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pay')->label('Record a payment')->icon(Heroicon::OutlinedBanknotes)->color('success')
                ->visible(fn (VendorInvoice $record) => $record->status === 'open')
                ->schema([
                    TextInput::make('amount')->numeric()->minValue(0.01)->required()->default(fn (VendorInvoice $record) => $record->balanceMinor() / 100),
                    Select::make('method')->options(VendorPayment::METHODS)->required()->default('momo'),
                    TextInput::make('reference')->label('Reference (transaction ID, bank slip)')->maxLength(120),
                    DatePicker::make('received_on')->default(now())->required(),
                ])
                ->action(fn (VendorInvoice $record, array $data) => VendorUi::run(fn ($actor) => app(VendorBilling::class)->pay($actor, $record,
                    VendorUi::toMinor($data['amount'], $record->currency), $data['method'], $data['reference'] ?? null, $data['received_on']), 'Payment recorded')),
            Action::make('print')->label('Print / PDF')->icon(Heroicon::OutlinedPrinter)->color('gray')
                ->url(fn (VendorInvoice $record) => route('vendor.invoices.print', $record), true),
            Action::make('void')->label('Void')->color('danger')->visible(fn (VendorInvoice $record) => $record->status === 'open' && ! $record->payments()->exists())
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(fn (VendorInvoice $record, array $data) => VendorUi::run(fn ($actor) => app(VendorBilling::class)->void($actor, $record, $data['reason']), 'Voided')),
        ];
    }
}
