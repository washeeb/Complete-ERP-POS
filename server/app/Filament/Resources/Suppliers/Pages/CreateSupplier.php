<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Domain\Purchasing\SupplierService;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Support\Erp;
use App\Models\Supplier;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSupplier extends CreateRecord
{
    protected static string $resource = SupplierResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(SupplierService::class)->save($actor, new Supplier, $data));
    }
}
