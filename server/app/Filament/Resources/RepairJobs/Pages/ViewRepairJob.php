<?php

namespace App\Filament\Resources\RepairJobs\Pages;

use App\Filament\Resources\RepairJobs\RepairJobResource;
use App\Filament\Support\ReloadsAfterAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRepairJob extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = RepairJobResource::class;

    protected function getHeaderActions(): array
    {
        return RepairJobResource::jobActions();
    }
}
