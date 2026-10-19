<?php

namespace App\Filament\Vendor\Resources\Plans\Pages;

use App\Filament\Vendor\Resources\Plans\PlanResource;
use Filament\Resources\Pages\EditRecord;

class EditPlan extends EditRecord
{
    protected static string $resource = PlanResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return PlanResource::prepare($data, $this->form->getRawState());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
