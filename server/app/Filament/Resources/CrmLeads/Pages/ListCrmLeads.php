<?php

namespace App\Filament\Resources\CrmLeads\Pages;

use App\Filament\Resources\CrmLeads\CrmLeadResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCrmLeads extends ListRecords
{
    protected static string $resource = CrmLeadResource::class;

    public function getSubheading(): ?string
    {
        return 'Everyone who may buy: plan the next step with “Follow-up”, then “Make customer” or close them as lost.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New lead')];
    }
}
