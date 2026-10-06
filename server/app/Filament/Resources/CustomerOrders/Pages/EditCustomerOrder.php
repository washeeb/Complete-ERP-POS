<?php

namespace App\Filament\Resources\CustomerOrders\Pages;

use App\Domain\Sales\CustomerOrderService;
use App\Filament\Resources\CustomerOrders\CustomerOrderResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCustomerOrder extends EditRecord
{
    protected static string $resource = CustomerOrderResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data + CustomerOrderResource::toFormData($this->record);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return Erp::run(fn ($actor) => app(CustomerOrderService::class)->saveQuotation($actor, $record, $data));
    }

    protected function getRedirectUrl(): string
    {
        return CustomerOrderResource::getUrl('view', ['record' => $this->record]);
    }
}
