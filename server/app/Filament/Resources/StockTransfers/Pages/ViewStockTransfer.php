<?php

namespace App\Filament\Resources\StockTransfers\Pages;

use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Filament\Resources\Waybills\WaybillResource;
use App\Filament\Support\ReloadsAfterAction;
use App\Models\StockTransfer;
use App\Models\Waybill;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewStockTransfer extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = StockTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Opens the print view in a new tab: the browser's print preview, Print or Save as PDF.
            Action::make('print')->label('Print')->icon(Heroicon::OutlinedPrinter)->color('gray')->url(fn () => route('print.transfer', $this->record), shouldOpenInNewTab: true),
            ...StockTransferResource::transferActions(),
            Action::make('waybill')->label('Waybill')->icon('heroicon-o-truck')->color('gray')
                ->url(fn (StockTransfer $record) => ($w = Waybill::where('source_id', $record->id)->where('status', '<>', 'cancelled')->first())
                    ? WaybillResource::getUrl('view', ['record' => $w])
                    : WaybillResource::getUrl('create', ['kind' => 'transfer', 'source' => $record->id])),
            EditAction::make()];
    }
}
