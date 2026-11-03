<?php

namespace App\Filament\Pages;

use App\Filament\Resources\IotSensors\IotSensorResource;
use App\Filament\Support\Erp;
use App\Models\IotAlarm;
use App\Models\IotCheck;
use App\Models\IotDay;
use App\Models\IotSensor;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Sensors → Temperature log: a month for one fridge, cold room, freezer or room — each day's lowest, highest and
 * average, minutes out of range, the signed checks, and every alarm with what was done and by whom. Printed (or saved
 * as PDF from the browser) for FDA / Good Pharmacy Practice inspections.
 */
class TemperatureLog extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Sensors';

    protected static ?string $navigationLabel = 'Temperature log';

    protected static ?string $title = 'Temperature log';

    protected static ?string $slug = 'temperature-log';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.temperature-log';

    #[Url]
    public ?string $sensor = null;

    #[Url]
    public string $month = '';

    public static function canAccess(): bool
    {
        return IotSensorResource::allowed('iot.view');
    }

    public function mount(): void
    {
        $this->month = preg_match('/^\d{4}-\d{2}$/', $this->month) ? $this->month : now()->format('Y-m');
        $this->sensor ??= array_key_first($this->sensors());
    }

    /** @return array<string, string> */
    public function sensors(): array
    {
        return IotSensorResource::scoped(IotSensor::query())->whereIn('kind', ['fridge', 'cold_room', 'freezer', 'room'])->orderBy('name')->pluck('name', 'id')->all();
    }

    public function data(): ?array
    {
        $s = $this->sensor ? IotSensorResource::scoped(IotSensor::query())->find($this->sensor) : null;
        if (! $s) {
            return null;
        }
        $tz = Erp::user()?->tenant?->timezone ?: 'UTC';
        $from = CarbonImmutable::parse($this->month.'-01', $tz)->startOfMonth();
        $to = $from->endOfMonth();
        $days = IotDay::where('sensor_id', $s->id)->whereBetween('day', [$from->toDateString(), $to->toDateString()])->get()->keyBy(fn ($d) => $d->day->toDateString());
        $checks = IotCheck::where('sensor_id', $s->id)->whereBetween('checked_at', [$from->utc(), $to->utc()])->orderBy('checked_at')->get()
            ->groupBy(fn ($c) => $c->checked_at->timezone($tz)->toDateString());
        $alarms = IotAlarm::where('sensor_id', $s->id)->where('started_at', '<=', $to->utc())
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $from->utc()))->orderBy('started_at')->get();
        $rows = [];
        for ($d = $from; $d->lte($to) && $d->lte(now($tz)); $d = $d->addDay()) {
            $key = $d->toDateString();
            $rows[] = ['date' => $d, 'day' => $days[$key] ?? null, 'checks' => $checks[$key] ?? collect()];
        }

        return ['sensor' => $s, 'rows' => $rows, 'alarms' => $alarms, 'tz' => $tz, 'from' => $from,
            'out' => (int) $days->sum('out_minutes'), 'min' => $days->min('min_temp'), 'max' => $days->max('max_temp')];
    }
}
