<?php

namespace App\Filament\Resources\CrmDeals\Pages;

use App\Domain\Crm\Crm;
use App\Filament\Resources\CrmDeals\CrmDealResource;
use App\Filament\Support\Erp;
use App\Models\CrmDeal;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCrmDeal extends CreateRecord
{
    protected static string $resource = CrmDealResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(Crm::class)->saveDeal($actor, new CrmDeal, CrmDealResource::toDomain($data)));
    }

    protected function getRedirectUrl(): string
    {
        return CrmDealResource::getUrl('edit', ['record' => $this->record]);
    }
}
