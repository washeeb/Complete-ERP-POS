<?php

namespace App\Filament\Widgets\Panels;

use App\Filament\Pages\IotAlarms;
use App\Filament\Resources\IotSensors\IotSensorResource;
use App\Models\IotAlarm;
use App\Models\IotCheck;
use App\Models\IotSensor;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Dashboard: sensors — alarms waiting, sensors out of range or silent, fridges not checked today, lowest batteries. */
class IotPanel extends StatsOverviewWidget
{
    use PanelScope;

    public int|string|array $columnSpan = 'full'; // public: the dashboard sets each panel's width

    protected ?string $heading = 'Sensors';

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $sensors = fn () => IotSensorResource::scoped(IotSensor::query())->where('is_active', true);
        $open = IotSensorResource::scoped(IotAlarm::query())->where('status', 'open')->count();
        $alarm = $sensors()->where('status', 'alarm')->count();
        $silent = $sensors()->where('status', 'silent')->count();
        $fridges = $sensors()->whereIn('kind', ['fridge', 'cold_room', 'freezer'])->pluck('id');
        $checked = IotCheck::whereIn('sensor_id', $fridges)->where('checked_at', '>=', self::today()->utc())->distinct('sensor_id')->count('sensor_id');
        $low = $sensors()->whereNotNull('battery')->where('battery', '<', 20)->count();

        return [
            Stat::make('Alarms waiting', (string) $open)->color($open ? 'danger' : 'success')->description($open ? 'Acknowledge them with what was done' : 'None')->url(IotAlarms::getUrl()),
            Stat::make('Sensors in alarm', (string) $alarm)->description($silent ? "{$silent} silent" : 'None silent')->color($alarm || $silent ? 'danger' : 'success')
                ->url(IotSensorResource::getUrl('index')),
            Stat::make('Fridges checked today', $checked.' of '.$fridges->count())->color($checked < $fridges->count() ? 'warning' : 'success'),
            Stat::make('Batteries low', (string) $low)->color($low ? 'warning' : 'success'),
        ];
    }

    public static function canView(): bool
    {
        return IotSensorResource::allowed('iot.view');
    }
}
