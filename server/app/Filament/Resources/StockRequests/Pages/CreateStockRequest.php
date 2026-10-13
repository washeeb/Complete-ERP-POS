<?php

namespace App\Filament\Resources\StockRequests\Pages;

use App\Domain\Inventory\StockRequestService;
use App\Filament\Resources\StockRequests\StockRequestResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStockRequest extends CreateRecord
{
    protected static string $resource = StockRequestResource::class;

    protected static ?string $title = 'Ask for stock';

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(StockRequestService::class)->request($actor, $data));
    }

    protected function getRedirectUrl(): string
    {
        return StockRequestResource::getUrl('view', ['record' => $this->record]);
    }
}
