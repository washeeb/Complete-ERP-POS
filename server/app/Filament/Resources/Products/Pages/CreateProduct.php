<?php

namespace App\Filament\Resources\Products\Pages;

use App\Domain\Catalog\CatalogService;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\Erp;
use App\Models\Product;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        if (! Erp::canAnywhere('pricing.manage')) {
            unset($data['prices']); // the service checks the product's industry
        }

        return Erp::run(fn ($actor) => app(CatalogService::class)->saveProduct($actor, new Product, $data));
    }
}
