<?php

namespace App\Filament\Resources\ModifierGroups\Pages;

use App\Domain\Restaurant\RestaurantSetup;
use App\Filament\Resources\ModifierGroups\ModifierGroupResource;
use App\Filament\Support\Erp;
use App\Models\ModifierGroup;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListModifierGroups extends ListRecords
{
    protected static string $resource = ModifierGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(fn (array $data) => Erp::run(fn ($actor) => app(RestaurantSetup::class)->saveModifierGroup($actor, new ModifierGroup, $data)))];
    }
}
