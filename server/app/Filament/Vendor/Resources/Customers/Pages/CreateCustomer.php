<?php

namespace App\Filament\Vendor\Resources\Customers\Pages;

use App\Filament\Vendor\Resources\Customers\CustomerResource;
use App\Models\Vendor\VendorCustomer;
use App\Support\Codes;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    /** The client's short code comes from its name (Oheneba Ventures → OHENEVENTU). */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ['code' => Codes::fromName((string) ($data['name'] ?? ''), VendorCustomer::pluck('code'), 10, fallback: 'CLIENT')] + $data;
    }
}
