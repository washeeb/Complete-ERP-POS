<?php

namespace App\Filament\Resources\CrmCampaigns\Pages;

use App\Domain\Crm\Crm;
use App\Filament\Resources\CrmCampaigns\CrmCampaignResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCrmCampaign extends EditRecord
{
    protected static string $resource = CrmCampaignResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $a = $this->record->audience ?? [];
        $a['min_spent'] = isset($a['min_spent_minor']) ? $a['min_spent_minor'] / 100 : null;
        $data['audience'] = $a;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return Erp::run(fn ($actor) => app(Crm::class)->saveCampaign($actor, $record, CrmCampaignResource::toDomain($data)));
    }

    protected function getRedirectUrl(): string
    {
        return CrmCampaignResource::getUrl('index');
    }
}
