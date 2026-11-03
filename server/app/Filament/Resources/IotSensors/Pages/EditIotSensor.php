<?php

namespace App\Filament\Resources\IotSensors\Pages;

use App\Domain\Iot\Sensors;
use App\Filament\Resources\IotSensors\IotSensorResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditIotSensor extends EditRecord
{
    protected static string $resource = IotSensorResource::class;

    protected function getHeaderActions(): array
    {
        return IotSensorResource::sensorActions();
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return Erp::run(fn ($actor) => app(Sensors::class)->save($actor, $record, $data));
    }

    protected function getFormActions(): array
    {
        return IotSensorResource::allowed('iot.manage') ? parent::getFormActions() : [];
    }
}
