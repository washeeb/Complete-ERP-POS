<?php

namespace App\Filament\Resources\RepairJobs\Pages;

use App\Domain\Electronics\RepairService;
use App\Filament\Resources\RepairJobs\RepairJobResource;
use App\Filament\Support\Erp;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRepairJobs extends ListRecords
{
    protected static string $resource = RepairJobResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New repair job')->using(fn (array $data) => Erp::run(fn ($actor) => app(RepairService::class)->open($actor, $data)))];
    }
}
