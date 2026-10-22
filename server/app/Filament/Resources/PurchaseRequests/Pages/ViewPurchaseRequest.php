<?php

namespace App\Filament\Resources\PurchaseRequests\Pages;

use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Support\ReloadsAfterAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewPurchaseRequest extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = PurchaseRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Opens the print view in a new tab: the browser's print preview, Print or Save as PDF.
            Action::make('print')->label('Print')->icon(Heroicon::OutlinedPrinter)->color('gray')->url(fn () => route('print.purchase-request', $this->record), shouldOpenInNewTab: true),
            ...PurchaseRequestResource::workflowActions(),
        ];
    }
}
