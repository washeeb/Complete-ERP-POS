<?php

namespace App\Filament\Resources\DiningOrders\Pages;

use App\Filament\Resources\DiningOrders\DiningOrderResource;
use Filament\Resources\Pages\ListRecords;

class ListDiningOrders extends ListRecords
{
    protected static string $resource = DiningOrderResource::class;
}
