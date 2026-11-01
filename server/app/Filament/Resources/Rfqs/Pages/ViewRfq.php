<?php

namespace App\Filament\Resources\Rfqs\Pages;

use App\Filament\Resources\Rfqs\RfqResource;
use App\Filament\Support\ReloadsAfterAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRfq extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = RfqResource::class;

    protected function getHeaderActions(): array
    {
        return RfqResource::workflowActions();
    }
}
