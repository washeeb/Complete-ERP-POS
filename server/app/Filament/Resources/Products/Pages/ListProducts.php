<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Pages\ImportData;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\Erp;
use App\Models\Product;
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
            Action::make('importCsv')->label('Add new products (file)')->icon(Heroicon::OutlinedArrowUpTray)->color('gray')->visible(fn () => Erp::canAnywhere('catalog.manage'))->url(ImportData::getUrl(['type' => 'products'])),
            Action::make('updateCsv')->label('Update products (file)')->icon(Heroicon::OutlinedArrowPath)->color('gray')->visible(fn () => Erp::canAnywhere('catalog.manage'))->url(ImportData::getUrl(['type' => 'products_update'])),
            Action::make('importPrices')->label('Import prices (CSV)')->icon(Heroicon::OutlinedCurrencyDollar)->color('gray')->visible(fn () => Erp::canAnywhere('pricing.manage'))->url(ImportData::getUrl(['type' => 'prices'])),
            Action::make('batchesAll')->label('Switch on batches for all')->icon(Heroicon::OutlinedCalendarDays)->color('gray')
                ->visible(fn () => Erp::canAnywhere('catalog.manage'))->requiresConfirmation()
                ->modalDescription(fn () => number_format(Product::where('track_stock', true)->where('track_batches', false)->count()).' products in stock are not switched on. '.ProductResource::BATCHES_NOTE)
                ->action(fn () => ProductResource::switchOnBatches(Product::where('track_stock', true)->where('track_batches', false)->get())),
            CreateAction::make(),
        ];
    }
}
