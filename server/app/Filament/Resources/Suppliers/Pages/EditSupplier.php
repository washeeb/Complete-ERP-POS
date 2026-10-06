<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Domain\Purchasing\SupplierService;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditSupplier extends EditRecord
{
    protected static string $resource = SupplierResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data + SupplierResource::toFormData($this->record);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return Erp::run(fn ($actor) => app(SupplierService::class)->save($actor, $record, $data));
    }
}
