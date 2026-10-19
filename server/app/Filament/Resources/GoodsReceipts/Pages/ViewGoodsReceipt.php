<?php

namespace App\Filament\Resources\GoodsReceipts\Pages;

use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Resources\Waybills\WaybillResource;
use App\Models\GoodsReceipt;
use App\Models\Waybill;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewGoodsReceipt extends ViewRecord
{
    protected static string $resource = GoodsReceiptResource::class;

    protected function getHeaderActions(): array
    {
        return [...GoodsReceiptResource::receiptActions(),
            Action::make('waybill')->label('Supplier waybill')->icon('heroicon-o-truck')->color('gray')
                ->url(fn (GoodsReceipt $record) => ($w = Waybill::where('source_id', $record->id)->where('status', '<>', 'cancelled')->first())
                    ? WaybillResource::getUrl('view', ['record' => $w])
                    : WaybillResource::getUrl('create', ['kind' => 'inbound', 'source' => $record->id])),
        ];
    }
}
