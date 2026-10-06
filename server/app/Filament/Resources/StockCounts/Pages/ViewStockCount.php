<?php

namespace App\Filament\Resources\StockCounts\Pages;

use App\Filament\Resources\StockCounts\StockCountResource;
use Filament\Resources\Pages\ViewRecord;

class ViewStockCount extends ViewRecord
{
    protected static string $resource = StockCountResource::class;

    protected function getHeaderActions(): array
    {
        return StockCountResource::countActions();
    }
}
