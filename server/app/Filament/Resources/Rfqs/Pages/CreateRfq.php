<?php

namespace App\Filament\Resources\Rfqs\Pages;

use App\Domain\Purchasing\RfqService;
use App\Filament\Resources\Rfqs\RfqResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRfq extends CreateRecord
{
    protected static string $resource = RfqResource::class;

    protected static ?string $title = 'Ask suppliers for prices';

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(RfqService::class)->create($actor, $data));
    }

    protected function getRedirectUrl(): string
    {
        return RfqResource::getUrl('view', ['record' => $this->record]);
    }
}
