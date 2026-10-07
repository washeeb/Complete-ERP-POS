<?php

namespace App\Filament\Resources\DiningTables\Pages;

use App\Domain\Restaurant\RestaurantSetup;
use App\Filament\Resources\DiningTables\DiningTableResource;
use App\Filament\Support\Erp;
use App\Models\DiningTable;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDiningTables extends ListRecords
{
    protected static string $resource = DiningTableResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(fn (array $data) => Erp::run(fn ($actor) => app(RestaurantSetup::class)->saveTable($actor, new DiningTable, $data)))];
    }
}
