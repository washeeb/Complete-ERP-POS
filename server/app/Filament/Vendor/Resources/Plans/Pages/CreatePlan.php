<?php

namespace App\Filament\Vendor\Resources\Plans\Pages;

use App\Filament\Vendor\Resources\Plans\PlanResource;
use App\Models\Vendor\VendorPlan;
use App\Support\Codes;
use Filament\Resources\Pages\CreateRecord;

class CreatePlan extends CreateRecord
{
    protected static string $resource = PlanResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // The code comes from the name, like every code the system gives.
        return ['code' => Codes::fromName((string) ($data['name'] ?? ''), VendorPlan::pluck('code'), 12, '_', 'PLAN')] + PlanResource::prepare($data, $this->form->getRawState());
    }
}
