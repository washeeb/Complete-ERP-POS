<?php

namespace App\Filament\Resources\SalesRoutes\Pages;

use App\Domain\Distribution\RouteService;
use App\Filament\Resources\SalesRoutes\SalesRouteResource;
use App\Filament\Support\Erp;
use App\Models\SalesRoute;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSalesRoutes extends ListRecords
{
    protected static string $resource = SalesRouteResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New route')->using(fn (array $data) => Erp::run(fn ($actor) => app(RouteService::class)->save($actor, new SalesRoute, SalesRouteResource::toDomain($data))))];
    }
}
