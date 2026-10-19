<?php

namespace App\Filament\Vendor\Resources\Invoices\Pages;

use App\Domain\Licensing\Vendor\VendorBilling;
use App\Filament\Vendor\Resources\Invoices\InvoiceResource;
use App\Filament\Vendor\VendorUi;
use App\Models\Vendor\VendorCustomer;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $lines = array_map(fn ($l) => ['description' => $l['description'], 'qty' => (float) $l['qty'], 'unit_minor' => VendorUi::toMinor($l['unit'], $data['currency'])],
            array_values($data['lines'] ?? []));

        return VendorUi::run(fn ($actor) => app(VendorBilling::class)->invoice($actor, VendorCustomer::findOrFail($data['customer_id']), $lines, $data['currency'],
            null, (int) ($data['due_days'] ?? 14), $data['notes'] ?? null));
    }

    protected function getRedirectUrl(): string
    {
        return InvoiceResource::getUrl('view', ['record' => $this->record]);
    }
}
