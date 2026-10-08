<?php

namespace App\Filament\Resources\ServicePasses\Pages;

use App\Filament\Resources\ServicePasses\ServicePassResource;
use Filament\Resources\Pages\ListRecords;

class ListServicePasses extends ListRecords
{
    protected static string $resource = ServicePassResource::class;
}
