<?php

namespace App\Filament\Resources\CrmDeals\Pages;

use App\Filament\Pages\CrmPipeline;
use App\Filament\Resources\CrmDeals\CrmDealResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListCrmDeals extends ListRecords
{
    protected static string $resource = CrmDealResource::class;

    public function getSubheading(): ?string
    {
        return 'Sales you are working on. A deal is won by itself when its quotation is confirmed as an order.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pipeline')->label('Pipeline')->icon(Heroicon::OutlinedViewColumns)->color('gray')->url(fn () => CrmPipeline::getUrl()),
            CreateAction::make()->label('New deal'),
        ];
    }
}
