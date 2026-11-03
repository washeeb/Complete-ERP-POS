<?php

namespace App\Filament\Resources\CrmLeads\Pages;

use App\Domain\Crm\Crm;
use App\Filament\Resources\CrmLeads\CrmLeadResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCrmLead extends EditRecord
{
    protected static string $resource = CrmLeadResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['consents'] = collect($this->record->consents ?? [])->map(fn ($c) => (bool) ($c['granted'] ?? false))->all();
        $data['value'] = $this->record->value_minor === null ? null : $this->record->value_minor / 100;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return Erp::run(fn ($actor) => app(Crm::class)->saveLead($actor, $record, CrmLeadResource::toDomain($data)));
    }
}
