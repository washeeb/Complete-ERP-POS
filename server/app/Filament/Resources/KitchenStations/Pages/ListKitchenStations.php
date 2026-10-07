<?php

namespace App\Filament\Resources\KitchenStations\Pages;

use App\Domain\Restaurant\RestaurantSetup;
use App\Filament\Resources\KitchenStations\KitchenStationResource;
use App\Filament\Support\Erp;
use App\Models\KitchenStation;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKitchenStations extends ListRecords
{
    protected static string $resource = KitchenStationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(fn (array $data) => Erp::run(fn ($actor) => app(RestaurantSetup::class)->saveStation($actor, new KitchenStation, $data)))];
    }
}
