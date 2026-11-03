<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Domain\Service\ServiceDesk;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTicket extends CreateRecord
{
    protected static string $resource = TicketResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(ServiceDesk::class)->open($actor, $data + ['source' => ($data['kind'] ?? '') === 'internal' ? 'staff' : ($data['source'] ?? 'counter')]));
    }

    protected function getRedirectUrl(): string
    {
        return TicketResource::getUrl('view', ['record' => $this->record]);
    }
}
