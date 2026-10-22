<?php

namespace App\Filament\Resources\CustomerOrders\Pages;

use App\Filament\Resources\CustomerOrders\CustomerOrderResource;
use App\Filament\Support\ReloadsAfterAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCustomerOrder extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = CustomerOrderResource::class;

    public function getTitle(): string
    {
        return CustomerOrderResource::label($this->record);
    }

    protected function getHeaderActions(): array
    {
        return [...CustomerOrderResource::orderActions(), EditAction::make()];
    }
}
