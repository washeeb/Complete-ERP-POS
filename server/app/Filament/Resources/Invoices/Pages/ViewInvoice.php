<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\ReloadsAfterAction;
use Filament\Resources\Pages\ViewRecord;

class ViewInvoice extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return InvoiceResource::invoiceActions();
    }
}
