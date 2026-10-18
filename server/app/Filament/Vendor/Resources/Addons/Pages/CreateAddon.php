<?php

namespace App\Filament\Vendor\Resources\Addons\Pages;

use App\Filament\Vendor\Resources\Addons\AddonResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAddon extends CreateRecord
{
    protected static string $resource = AddonResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return AddonResource::prepare($data, $this->form->getRawState());
    }
}
