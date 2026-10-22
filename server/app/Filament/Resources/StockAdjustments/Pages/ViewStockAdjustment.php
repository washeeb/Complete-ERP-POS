<?php

namespace App\Filament\Resources\StockAdjustments\Pages;

use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Support\ReloadsAfterAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewStockAdjustment extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = StockAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [...StockAdjustmentResource::adjustmentActions(), EditAction::make()];
    }
}
