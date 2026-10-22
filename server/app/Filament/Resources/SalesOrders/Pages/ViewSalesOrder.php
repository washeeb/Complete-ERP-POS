<?php

namespace App\Filament\Resources\SalesOrders\Pages;

use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Filament\Support\ReloadsAfterAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSalesOrder extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = SalesOrderResource::class;

    protected function getHeaderActions(): array
    {
        return SalesOrderResource::orderActions();
    }
}
