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
        if (! Erp::can('pricing.manage')) {
            unset($data['prices']);
        }

        return Erp::run(fn ($actor) => app(CatalogService::class)->saveProduct($actor, new Product, $data));
    }
}
