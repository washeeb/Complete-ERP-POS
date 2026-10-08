<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Pages\ImportData;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Support\Erp;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListSuppliers extends ListRecords
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('importCsv')->label('Import suppliers (CSV)')->icon(Heroicon::OutlinedArrowUpTray)->color('gray')->visible(fn () => Erp::canAnywhere('suppliers.manage'))->url(ImportData::getUrl(['type' => 'suppliers'])), CreateAction::make()];
    }
}
