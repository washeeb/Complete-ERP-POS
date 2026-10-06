<?php

namespace App\Filament\Resources\PayrollRuns\Pages;

use App\Filament\Resources\PayrollRuns\PayrollRunResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPayrollRun extends ViewRecord
{
    protected static string $resource = PayrollRunResource::class;

    protected function getHeaderActions(): array
    {
        return [PayrollRunResource::prepareAction($this->record), ...PayrollRunResource::runActions()];
    }
}
