<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Domain\Customers\CustomerService;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Support\Erp;
use App\Models\Customer;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSale extends ViewRecord
{
    protected static string $resource = SaleResource::class;

    /** A copy of the receipt, marked COPY: look at it first, or send it straight to the print window. Each is audited. */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')->label('Print preview (copy)')->icon(Heroicon::OutlinedEye)->color('gray')
                ->url(fn () => route('print.receipt', $this->record), shouldOpenInNewTab: true),
            Action::make('print')->label('Print copy')->icon(Heroicon::OutlinedPrinter)
                ->url(fn () => route('print.receipt', ['sale' => $this->record, 'print' => 1]), shouldOpenInNewTab: true),
            // A walk-in who gave a name and phone: keep them as a customer for next time (the sale itself never changes).
            Action::make('saveCustomer')->label('Save as customer')->icon(Heroicon::OutlinedUserPlus)->color('gray')
                ->visible(fn () => ! $this->record->customer_id && $this->record->walk_in_phone && Erp::canAnywhere('customers.manage')
                    && ! Customer::where('phone', $this->record->walk_in_phone)->exists())
                ->schema([TextInput::make('name')->required()->maxLength(255)->default(fn () => $this->record->walk_in_name),
                    TextInput::make('phone')->required()->default(fn () => $this->record->walk_in_phone)])
                ->action(fn (array $data) => Erp::run(fn ($actor) => app(CustomerService::class)->save($actor, new Customer, ['name' => $data['name'], 'phone' => $data['phone']]),
                    'Customer saved')),
        ];
    }
}
