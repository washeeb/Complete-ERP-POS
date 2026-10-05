<?php

namespace App\Filament\Resources\Products\Pages;

use App\Domain\Catalog\CatalogService;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data + ProductResource::toFormData($this->record);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! Erp::can('pricing.manage')) {
            unset($data['prices']); // never wipe prices someone can't see
        }

        return Erp::run(fn ($actor) => app(CatalogService::class)->saveProduct($actor, $record, $data, $data['reason'] ?? null));
    }
}
