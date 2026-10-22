<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\ReloadsAfterAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCustomer extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [...CustomerResource::accountActions(), EditAction::make()];
    }
}
