<?php

namespace App\Filament\Vendor\Resources\Customers\Pages;

use App\Filament\Vendor\Resources\Customers\CustomerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;
}
