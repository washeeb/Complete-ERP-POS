<?php

namespace App\Filament\Resources\CrmCampaigns\Pages;

use App\Domain\Crm\Crm;
use App\Filament\Resources\CrmCampaigns\CrmCampaignResource;
use App\Filament\Support\Erp;
use App\Models\CrmCampaign;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCrmCampaign extends CreateRecord
{
    protected static string $resource = CrmCampaignResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(Crm::class)->saveCampaign($actor, new CrmCampaign, CrmCampaignResource::toDomain($data)));
    }

    protected function getRedirectUrl(): string
    {
        return CrmCampaignResource::getUrl('index'); // sent from the list ("Send now"), after a last look
    }
}
