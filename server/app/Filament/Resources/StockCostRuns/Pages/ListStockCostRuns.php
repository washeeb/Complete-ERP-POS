<?php

namespace App\Filament\Resources\StockCostRuns\Pages;

use App\Filament\Pages\EstimatedCosts;
use App\Filament\Resources\StockCostRuns\StockCostRunResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListStockCostRuns extends ListRecords
{
    protected static string $resource = StockCostRunResource::class;

    public function getSubheading(): ?string
    {
        return 'For products loaded without a cost: works a cost out from the selling price, so the stock has a value and sales have a profit. You see the result before anything changes.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('estimated')->label('Estimated against real costs')->icon(Heroicon::OutlinedScale)->color('gray')->url(EstimatedCosts::getUrl()),
            CreateAction::make()->label('New cost run'),
        ];
    }
}
