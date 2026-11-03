<?php

namespace App\Filament\Resources\CrmDeals\Pages;

use App\Domain\Crm\Crm;
use App\Filament\Resources\CrmDeals\CrmDealResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCrmDeal extends EditRecord
{
    protected static string $resource = CrmDealResource::class;

    protected function getHeaderActions(): array
    {
        return array_slice(CrmDealResource::dealActions(), 1); // not "Edit": this is it
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['value'] = ($this->record->value_minor ?? 0) / 100;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return Erp::run(fn ($actor) => app(Crm::class)->saveDeal($actor, $record, CrmDealResource::toDomain($data)));
    }
}
