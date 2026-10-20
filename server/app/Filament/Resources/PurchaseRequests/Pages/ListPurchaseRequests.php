<?php

namespace App\Filament\Resources\PurchaseRequests\Pages;

use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseRequests extends ListRecords
{
    protected static string $resource = PurchaseRequestResource::class;

    protected static ?string $title = 'Purchase requests';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Ask the buyers to order')];
    }
}
