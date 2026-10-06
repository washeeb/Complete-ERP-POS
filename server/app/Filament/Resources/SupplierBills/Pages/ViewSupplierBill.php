<?php

namespace App\Filament\Resources\SupplierBills\Pages;

use App\Filament\Resources\SupplierBills\SupplierBillResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSupplierBill extends ViewRecord
{
    protected static string $resource = SupplierBillResource::class;

    protected function getHeaderActions(): array
    {
        return SupplierBillResource::approvalActions();
    }
}
