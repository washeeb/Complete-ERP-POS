<?php

namespace App\Filament\Resources\StockAdjustments\Pages;

use App\Domain\Inventory\StockAdjustmentService;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Support\Erp;
use App\Models\StockAdjustment;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStockAdjustment extends CreateRecord
{
    protected static string $resource = StockAdjustmentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(StockAdjustmentService::class)->saveDraft($actor, new StockAdjustment, $data));
    }

    protected function getRedirectUrl(): string
    {
        return StockAdjustmentResource::getUrl('view', ['record' => $this->record]);
    }
}
