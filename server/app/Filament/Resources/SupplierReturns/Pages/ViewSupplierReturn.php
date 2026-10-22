<?php

namespace App\Filament\Resources\SupplierReturns\Pages;

use App\Filament\Resources\SupplierReturns\SupplierReturnResource;
use App\Filament\Support\ReloadsAfterAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSupplierReturn extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = SupplierReturnResource::class;

    protected function getHeaderActions(): array
    {
        return SupplierReturnResource::returnActions();
    }
}
