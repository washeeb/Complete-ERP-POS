<?php

namespace App\Filament\Resources\OfficeReturns\Pages;

use App\Filament\Resources\OfficeReturns\OfficeReturnResource;
use App\Filament\Support\ReloadsAfterAction;
use Filament\Resources\Pages\ViewRecord;

class ViewOfficeReturn extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = OfficeReturnResource::class;

    protected function getHeaderActions(): array
    {
        return OfficeReturnResource::documentActions();
    }
}
