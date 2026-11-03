<?php

namespace App\Filament\Resources\CrmCampaigns\Pages;

use App\Filament\Resources\CrmCampaigns\CrmCampaignResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCrmCampaigns extends ListRecords
{
    protected static string $resource = CrmCampaignResource::class;

    public function getSubheading(): ?string
    {
        return 'One message to many customers by SMS, WhatsApp or e-mail — only to those who agreed. Every message is in Messages, with whether it arrived.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New campaign')];
    }
}
