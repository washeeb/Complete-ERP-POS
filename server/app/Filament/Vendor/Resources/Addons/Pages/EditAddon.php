<?php

namespace App\Filament\Vendor\Resources\Addons\Pages;

use App\Filament\Vendor\Resources\Addons\AddonResource;
use Filament\Resources\Pages\EditRecord;

class EditAddon extends EditRecord
{
    protected static string $resource = AddonResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return AddonResource::prepare($data, $this->form->getRawState());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
