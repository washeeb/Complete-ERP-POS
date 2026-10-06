<?php

namespace App\Filament\Resources\StockTransfers\Pages;

use App\Domain\Inventory\TransferService;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Filament\Support\Erp;
use App\Models\StockTransfer;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStockTransfer extends CreateRecord
{
    protected static string $resource = StockTransferResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(TransferService::class)->saveDraft($actor, new StockTransfer, $data));
    }

    protected function getRedirectUrl(): string
    {
        return StockTransferResource::getUrl('view', ['record' => $this->record]);
    }
}
