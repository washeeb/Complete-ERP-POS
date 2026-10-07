<?php

namespace App\Filament\Resources\PayrollRuns\Pages;

use App\Filament\Pages\PayrollProfilePage;
use App\Filament\Resources\PayrollRuns\PayrollRunResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListPayrollRuns extends ListRecords
{
    protected static string $resource = PayrollRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rules')->label('Tax and SSNIT rules')->icon(Heroicon::OutlinedScale)->color('gray')->url(PayrollProfilePage::getUrl()),
            PayrollRunResource::prepareAction(),
        ];
    }
}
