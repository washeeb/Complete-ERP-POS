<?php

namespace App\Filament\Resources\LandedCosts\Pages;

use App\Filament\Resources\LandedCosts\LandedCostResource;
use Filament\Resources\Pages\ListRecords;

class ListLandedCosts extends ListRecords
{
    protected static string $resource = LandedCostResource::class;

    protected function getHeaderActions(): array
    {
        return [LandedCostResource::spreadAction()];
    }
}
