<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Support\ReloadsAfterAction;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewPurchaseOrder extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Opens the print view in a new tab: the browser's print preview, Print or Save as PDF.
            Action::make('print')->label('Print')->icon(Heroicon::OutlinedPrinter)->color('gray')->url(fn () => route('print.purchase-order', $this->record), shouldOpenInNewTab: true),
            ...PurchaseOrderResource::orderActions(), EditAction::make()];
    }
}
