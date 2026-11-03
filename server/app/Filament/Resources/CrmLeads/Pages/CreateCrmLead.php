<?php

namespace App\Filament\Resources\CrmLeads\Pages;

use App\Domain\Crm\Crm;
use App\Filament\Resources\CrmLeads\CrmLeadResource;
use App\Filament\Support\Erp;
use App\Models\CrmLead;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCrmLead extends CreateRecord
{
    protected static string $resource = CrmLeadResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(Crm::class)->saveLead($actor, new CrmLead, CrmLeadResource::toDomain($data)));
    }

    protected function getRedirectUrl(): string
    {
        return CrmLeadResource::getUrl('edit', ['record' => $this->record]);
    }
}
