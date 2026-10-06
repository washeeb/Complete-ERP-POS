<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Domain\Purchasing\PurchaseOrderService;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPurchaseOrder extends EditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data + PurchaseOrderResource::toFormData($this->record);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return Erp::run(fn ($actor) => app(PurchaseOrderService::class)->saveDraft($actor, $record, $data));
    }

    protected function getRedirectUrl(): string
    {
        return PurchaseOrderResource::getUrl('view', ['record' => $this->record]);
    }
}
