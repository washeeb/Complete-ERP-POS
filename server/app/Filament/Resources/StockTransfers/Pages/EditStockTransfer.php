<?php

namespace App\Filament\Resources\StockTransfers\Pages;

use App\Domain\Inventory\TransferService;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditStockTransfer extends EditRecord
{
    protected static string $resource = StockTransferResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data + StockTransferResource::toFormData($this->record);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return Erp::run(fn ($actor) => app(TransferService::class)->saveDraft($actor, $record, $data));
    }

    protected function getRedirectUrl(): string
    {
        return StockTransferResource::getUrl('view', ['record' => $this->record]);
    }
}
