<?php

namespace App\Filament\Resources\GoodsReceipts\Pages;

use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Resources\Waybills\WaybillResource;
use App\Filament\Support\ReloadsAfterAction;
use App\Models\GoodsReceipt;
use App\Models\Waybill;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewGoodsReceipt extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = GoodsReceiptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Opens the print view in a new tab: the browser's print preview, Print or Save as PDF.
            Action::make('print')->label('Print')->icon(Heroicon::OutlinedPrinter)->color('gray')->url(fn () => route('print.goods-receipt', $this->record), shouldOpenInNewTab: true),
            ...GoodsReceiptResource::receiptActions(),
            Action::make('waybill')->label('Supplier waybill')->icon('heroicon-o-truck')->color('gray')
                ->url(fn (GoodsReceipt $record) => ($w = Waybill::where('source_id', $record->id)->where('status', '<>', 'cancelled')->first())
                    ? WaybillResource::getUrl('view', ['record' => $w])
                    : WaybillResource::getUrl('create', ['kind' => 'inbound', 'source' => $record->id])),
        ];
    }
}
