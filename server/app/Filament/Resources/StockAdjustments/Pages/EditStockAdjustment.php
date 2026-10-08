<?php

namespace App\Filament\Resources\StockAdjustments\Pages;

use App\Domain\Inventory\StockAdjustmentService;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditStockAdjustment extends EditRecord
{
    protected static string $resource = StockAdjustmentResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data + StockAdjustmentResource::toFormData($this->record);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return Erp::run(fn ($actor) => app(StockAdjustmentService::class)->saveDraft($actor, $record, $data));
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): string
    {
        return StockAdjustmentResource::getUrl('view', ['record' => $this->record]);
    }
}
