<?php

namespace App\Filament\Resources\IotSensors\Pages;

use App\Filament\Resources\IotSensors\IotSensorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIotSensors extends ListRecords
{
    protected static string $resource = IotSensorResource::class;

    public function getSubheading(): ?string
    {
        return 'What each sensor reads now, refreshed every minute. Alarms go to the people on duty until someone says what was done.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New sensor')];
    }
}
