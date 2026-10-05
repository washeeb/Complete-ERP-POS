<?php

namespace App\Filament\Resources\Devices\Pages;

use App\Domain\Devices\DeviceService;
use App\Filament\Resources\Devices\DeviceResource;
use App\Filament\Support\Erp;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDevice extends CreateRecord
{
    protected static string $resource = DeviceResource::class;

    protected static ?string $title = 'Register device';

    protected function handleRecordCreation(array $data): Model
    {
        [$device, $code] = Erp::run(fn ($actor) => app(DeviceService::class)->register($actor, $data));
        DeviceResource::showCode($device, $code);

        return $device;
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null; // the code notification replaces it
    }

    protected function getRedirectUrl(): string
    {
        return DeviceResource::getUrl('index');
    }
}
