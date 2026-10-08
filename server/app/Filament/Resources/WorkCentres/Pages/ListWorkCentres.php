<?php

namespace App\Filament\Resources\WorkCentres\Pages;

use App\Domain\Manufacturing\BomService;
use App\Filament\Resources\WorkCentres\WorkCentreResource;
use App\Filament\Support\Erp;
use App\Models\WorkCentre;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkCentres extends ListRecords
{
    protected static string $resource = WorkCentreResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New work centre')->using(fn (array $data) => Erp::run(fn ($actor) => app(BomService::class)->saveWorkCentre($actor, new WorkCentre, $data)))];
    }
}
