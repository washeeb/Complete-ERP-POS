<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Pages\ImportData;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\Erp;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importCsv')->label('Import products (CSV)')->icon(Heroicon::OutlinedArrowUpTray)->color('gray')->visible(fn () => Erp::canAnywhere('catalog.manage'))->url(ImportData::getUrl(['type' => 'products'])),
            Action::make('importPrices')->label('Import prices (CSV)')->icon(Heroicon::OutlinedCurrencyDollar)->color('gray')->visible(fn () => Erp::canAnywhere('pricing.manage'))->url(ImportData::getUrl(['type' => 'prices'])),
            CreateAction::make(),
        ];
    }
}
