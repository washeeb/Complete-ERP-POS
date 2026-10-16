<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Filament\Resources\Sales\SaleResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSale extends ViewRecord
{
    protected static string $resource = SaleResource::class;

    /** A copy of the receipt, marked COPY: look at it first, or send it straight to the print window. Each is audited. */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')->label('Print preview (copy)')->icon(Heroicon::OutlinedEye)->color('gray')
                ->url(fn () => route('print.receipt', $this->record), shouldOpenInNewTab: true),
            Action::make('print')->label('Print copy')->icon(Heroicon::OutlinedPrinter)
                ->url(fn () => route('print.receipt', ['sale' => $this->record, 'print' => 1]), shouldOpenInNewTab: true),
        ];
    }
}
