<?php

namespace App\Filament\Resources\SupplierBills\Pages;

use App\Filament\Resources\SupplierBills\SupplierBillResource;
use App\Filament\Support\ReloadsAfterAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSupplierBill extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = SupplierBillResource::class;

    protected function getHeaderActions(): array
    {
        return SupplierBillResource::approvalActions();
    }
}
