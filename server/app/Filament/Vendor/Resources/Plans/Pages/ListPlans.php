<?php

namespace App\Filament\Vendor\Resources\Plans\Pages;

use App\Filament\Vendor\Resources\Plans\PlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlans extends ListRecords
{
    protected static string $resource = PlanResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
