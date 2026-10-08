<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Pages\ImportData;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\Erp;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('importCsv')->label('Import customers (CSV)')->icon(Heroicon::OutlinedArrowUpTray)->color('gray')->visible(fn () => Erp::canAnywhere('customers.manage'))->url(ImportData::getUrl(['type' => 'customers'])), CreateAction::make()];
    }
}
