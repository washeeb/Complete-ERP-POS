<?php

namespace App\Filament\Resources\Waybills\Pages;

use App\Domain\Logistics\WaybillService;
use App\Filament\Resources\Waybills\WaybillResource;
use App\Filament\Support\Erp;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Url;

/** A waybill for a document; opened from a transfer, return or goods received with ?kind=&source= filled in. */
class CreateWaybill extends CreateRecord
{
    protected static string $resource = WaybillResource::class;

    #[Url]
    public ?string $kind = null;

    #[Url]
    public ?string $source = null;

    protected function afterFill(): void
    {
        if ($this->kind) {
            $this->form->fill(['kind' => $this->kind, 'source_id' => $this->source]);
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(WaybillService::class)->prepare($actor, $data['kind'], $data['source_id'], $data), 'Waybill prepared');
    }

    protected function getRedirectUrl(): string
    {
        return WaybillResource::getUrl('view', ['record' => $this->record]);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }
}
