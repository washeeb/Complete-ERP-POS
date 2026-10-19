<?php

namespace App\Filament\Vendor\Resources\Plans\Pages;

use App\Filament\Vendor\Resources\Plans\PlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePlan extends CreateRecord
{
    protected static string $resource = PlanResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return PlanResource::prepare($data, $this->form->getRawState());
    }
}
