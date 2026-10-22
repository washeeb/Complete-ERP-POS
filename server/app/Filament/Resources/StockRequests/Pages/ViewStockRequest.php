<?php

namespace App\Filament\Resources\StockRequests\Pages;

use App\Filament\Resources\StockRequests\StockRequestResource;
use App\Filament\Support\ReloadsAfterAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewStockRequest extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = StockRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Opens the print view in a new tab: the browser's print preview, Print or Save as PDF.
            Action::make('print')->label('Print')->icon(Heroicon::OutlinedPrinter)->color('gray')->url(fn () => route('print.stock-request', $this->record), shouldOpenInNewTab: true),
            ...StockRequestResource::requestActions(),
        ];
    }
}
