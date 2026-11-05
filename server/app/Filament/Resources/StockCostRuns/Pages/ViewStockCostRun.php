<?php

namespace App\Filament\Resources\StockCostRuns\Pages;

use App\Filament\Resources\StockCostRuns\StockCostRunResource;
use App\Filament\Support\ReloadsAfterAction;
use Filament\Resources\Pages\ViewRecord;

class ViewStockCostRun extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = StockCostRunResource::class;

    protected function getHeaderActions(): array
    {
        return StockCostRunResource::runActions();
    }
}
