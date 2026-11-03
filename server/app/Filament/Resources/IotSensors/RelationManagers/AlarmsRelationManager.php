<?php

namespace App\Filament\Resources\IotSensors\RelationManagers;

use App\Filament\Pages\IotAlarms;
use App\Models\IotAlarm;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/** A sensor's alarms: when, how long, the worst reading, what was done and by whom. */
class AlarmsRelationManager extends RelationManager
{
    protected static string $relationship = 'alarms';

    protected static ?string $title = 'Alarms';

    public function table(Table $table): Table
    {
        return IotAlarms::columns($table->defaultSort('started_at', 'desc')->emptyStateHeading('No alarms'), withSensor: false)
            ->recordActions(IotAlarms::actions());
    }

    public static function duration(IotAlarm $a): string
    {
        $m = $a->minutes();

        return $m >= 60 ? intdiv($m, 60).' h '.($m % 60).' min' : $m.' min';
    }
}
