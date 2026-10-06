<?php

namespace App\Filament\Resources\CustomerOrders\Pages;

use App\Domain\Sales\CustomerOrderService;
use App\Filament\Resources\CustomerOrders\CustomerOrderResource;
use App\Filament\Support\Erp;
use App\Models\CustomerOrder;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCustomerOrder extends CreateRecord
{
    protected static string $resource = CustomerOrderResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(CustomerOrderService::class)->saveQuotation($actor, new CustomerOrder, $data));
    }

    protected function getRedirectUrl(): string
    {
        return CustomerOrderResource::getUrl('view', ['record' => $this->record]);
    }
}
