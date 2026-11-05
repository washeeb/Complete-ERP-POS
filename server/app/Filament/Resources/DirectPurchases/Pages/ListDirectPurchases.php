<?php

namespace App\Filament\Resources\DirectPurchases\Pages;

use App\Filament\Resources\DirectPurchases\DirectPurchaseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDirectPurchases extends ListRecords
{
    protected static string $resource = DirectPurchaseResource::class;

    public function getSubheading(): ?string
    {
        return 'Goods that arrive with a supplier\'s invoice and no purchase order, paid on delivery, on credit or on consignment.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Enter a supplier invoice')];
    }
}
