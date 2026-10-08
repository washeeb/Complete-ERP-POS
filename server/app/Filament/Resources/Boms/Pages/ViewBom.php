<?php

namespace App\Filament\Resources\Boms\Pages;

use App\Filament\Resources\Boms\BomResource;
use Filament\Resources\Pages\ViewRecord;

class ViewBom extends ViewRecord
{
    protected static string $resource = BomResource::class;

    protected function getHeaderActions(): array
    {
        return BomResource::bomActions();
    }
}
