<?php

namespace App\Filament\Resources\StockAdjustments\Pages;

use App\Filament\Pages\ImportData;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Support\Erp;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListStockAdjustments extends ListRecords
{
    protected static string $resource = StockAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fromCsv')->label('Update stock from a CSV file')->icon(Heroicon::OutlinedArrowUpTray)->color('gray')
                ->visible(fn () => Erp::canAnywhere('stock.adjust'))->url(ImportData::getUrl(['type' => 'stock_adjustment'])),
            CreateAction::make()->label('New stock adjustment'),
        ];
    }
}
