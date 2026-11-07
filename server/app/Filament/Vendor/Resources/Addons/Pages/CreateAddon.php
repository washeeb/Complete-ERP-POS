<?php

namespace App\Filament\Vendor\Resources\Addons\Pages;

use App\Filament\Vendor\Resources\Addons\AddonResource;
use App\Models\Vendor\VendorAddon;
use App\Support\Codes;
use Filament\Resources\Pages\CreateRecord;

class CreateAddon extends CreateRecord
{
    protected static string $resource = AddonResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // The code comes from the name, like every code the system gives.
        return ['code' => Codes::fromName((string) ($data['name'] ?? ''), VendorAddon::pluck('code'), 12, '_', 'ADDON')] + AddonResource::prepare($data, $this->form->getRawState());
    }
}
