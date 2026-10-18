<?php

namespace App\Filament\Resources\SupplierReturns\Pages;

use App\Filament\Resources\SupplierReturns\SupplierReturnResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSupplierReturn extends ViewRecord
{
    protected static string $resource = SupplierReturnResource::class;

    protected function getHeaderActions(): array
    {
        return SupplierReturnResource::returnActions();
    }
}
