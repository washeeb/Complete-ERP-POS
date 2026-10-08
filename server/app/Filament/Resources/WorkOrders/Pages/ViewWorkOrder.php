<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use Filament\Resources\Pages\ViewRecord;

class ViewWorkOrder extends ViewRecord
{
    protected static string $resource = WorkOrderResource::class;

    protected function getHeaderActions(): array
    {
        return WorkOrderResource::orderActions();
    }
}
