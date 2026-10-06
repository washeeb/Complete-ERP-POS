<?php

namespace App\Filament\Resources\CustomerOrders\Pages;

use App\Filament\Resources\CustomerOrders\CustomerOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomerOrders extends ListRecords
{
    protected static string $resource = CustomerOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New quotation')];
    }
}
