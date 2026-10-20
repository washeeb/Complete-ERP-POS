<?php

namespace App\Filament\Resources\PurchaseRequests\Pages;

use App\Domain\Purchasing\PurchaseRequestService;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePurchaseRequest extends CreateRecord
{
    protected static string $resource = PurchaseRequestResource::class;

    protected static ?string $title = 'Ask the buyers to order';

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(PurchaseRequestService::class)->request($actor, $data));
    }

    protected function getRedirectUrl(): string
    {
        return PurchaseRequestResource::getUrl('view', ['record' => $this->record]);
    }
}
