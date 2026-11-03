<?php

namespace App\Filament\Resources\IotSensors\Pages;

use App\Domain\Iot\Sensors;
use App\Filament\Resources\IotSensors\IotSensorResource;
use App\Filament\Support\Erp;
use App\Models\IotSensor;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateIotSensor extends CreateRecord
{
    protected static string $resource = IotSensorResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(Sensors::class)->save($actor, new IotSensor, $data));
    }

    protected function getRedirectUrl(): string
    {
        return IotSensorResource::getUrl('edit', ['record' => $this->record]);
    }
}
