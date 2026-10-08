<?php

namespace App\Filament\Resources\RepTargets\Pages;

use App\Domain\Distribution\RouteService;
use App\Filament\Resources\RepTargets\RepTargetResource;
use App\Filament\Support\Erp;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRepTargets extends ListRecords
{
    protected static string $resource = RepTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Set a target')->using(fn (array $data) => Erp::run(fn ($actor) => app(RouteService::class)->setTarget($actor, $data)))];
    }
}
