<?php

namespace App\Domain\Office;

use App\Filament\Pages\IotClouds;
use App\Filament\Pages\TemperatureLog;
use App\Filament\Resources\IotSensors\IotSensorResource;
use App\Filament\Resources\MaintenanceOrders\MaintenanceOrderResource;
use App\Models\Branch;
use App\Models\IotAlarm;
use App\Models\IotConnection;
use App\Models\IotReading;
use App\Models\IotSensor;
use App\Models\MaintenanceAsset;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sensors in the new back office (part 32 of the rewrite), as the current pages: Sensors (list, one sensor with its
 * reading now, the last 24 hours, its alarms and checks), Alarms, Temperature log and Sensor clouds. Same queries (scoped
 * to the person's branches) and rules: the Sensors and Clouds services (SensorsController). Cloud passwords and secrets
 * are never sent back.
 */
final class SensorsScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const STATUS_TONE = ['ok' => 'success', 'alarm' => 'danger', 'silent' => 'warning', 'waiting' => 'subtle', 'off' => 'subtle'];

    public const ALARM_TONE = ['open' => 'danger', 'acknowledged' => 'warning', 'closed' => 'success'];

    public function __construct(private readonly User $user) {}

    private static function choices(array $map): array
    {
        return collect($map)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private static function page(array $p): array
    {
        return [in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25, max(1, (int) ($p['page'] ?? 1))];
    }

    private static function num(mixed $v): string
    {
        $v = (string) ($v ?? '');

        return str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v;
    }

    private function tz(): string
    {
        return $this->user->tenant?->timezone ?: 'UTC';
    }

    public static function canView(): bool
    {
        return IotSensorResource::allowed('iot.view');
    }

    public static function canManage(): bool
    {
        return IotSensorResource::allowed('iot.manage');
    }

    public static function canRespond(): bool
    {
        return IotSensorResource::allowed('iot.respond');
    }

    public static function canLog(): bool
    {
        return TemperatureLog::canAccess();
    }

    public static function canClouds(): bool
    {
        return IotClouds::canAccess();
    }

    public static function reading(IotSensor $s): string
    {
        return match (true) {
            $s->kind === 'power' => $s->last_power === null ? '—' : ($s->last_power ? 'On' : 'OFF'),
            $s->kind === 'door' => $s->last_door_open === null ? '—' : ($s->last_door_open ? 'OPEN' : 'Closed'),
            default => $s->last_temp === null ? '—' : self::num($s->last_temp).' °C'.($s->last_humidity !== null ? ' · '.$s->last_humidity.' %' : ''),
        };
    }

    // -------------------------------------------------------------------------------------------- Sensors

    public function query(): Builder
    {
        return IotSensorResource::getEloquentQuery();
    }

    /** @param  array<string, mixed>  $p  q, status, kind, branch, show, page, per */
    public function sensors(array $p): array
    {
        $q = $this->query();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('iot_sensors.name', 'ilike', "%{$s}%")->orWhere('iot_sensors.number', 'ilike', "%{$s}%"));
        }
        if (isset(IotSensor::STATUSES[$p['status'] ?? ''])) {
            $q->where('iot_sensors.status', $p['status']);
        }
        if (isset(IotSensor::KINDS[$p['kind'] ?? ''])) {
            $q->where('iot_sensors.kind', $p['kind']);
        }
        if (($p['branch'] ?? '') !== '') {
            $q->where('iot_sensors.branch_id', $p['branch']);
        }
        match ($p['show'] ?? '') {
            'archived' => $q->whereNotNull('iot_sensors.archived_at'), 'both' => null, default => $q->whereNull('iot_sensors.archived_at')
        };
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $archive = new CompanyScreen($this->user);

        return ['total' => $total, 'page' => $page, 'per' => $per, 'statuses' => self::choices(IotSensor::STATUSES),
            'kinds' => self::choices(collect(IotSensor::KINDS)->map(fn ($k) => $k[0])->all()),
            'branches' => self::choices(Branch::orderBy('code')->pluck('name', 'id')->all()), 'can_create' => IotSensorResource::canCreate(),
            'rows' => $q->orderBy('iot_sensors.name')->forPage($page, $per)->get()->map(fn (IotSensor $s) => ['id' => $s->id, 'name' => $s->name,
                'where' => $s->branch?->name.' · '.(IotSensor::KINDS[$s->kind][0] ?? $s->kind), 'reading' => self::reading($s), 'range' => $s->range(),
                'status' => $s->status, 'status_label' => IotSensor::STATUSES[$s->status] ?? $s->status, 'tone' => self::STATUS_TONE[$s->status] ?? 'subtle',
                'last' => $s->last_reading_at?->diffForHumans(), 'battery' => $s->battery, 'via' => IotSensor::CONNECTIONS[$s->connection] ?? $s->connection]
                + $archive->archiveOf($s))->all()];
    }

    /** The defaults each kind of sensor starts with (range), for the form. */
    private static function kindDefaults(): array
    {
        return collect(IotSensor::KINDS)->map(fn ($k, $id) => ['id' => $id, 'label' => $k[0], 'min_temp' => self::num($k[1]), 'max_temp' => self::num($k[2]),
            'min_humidity' => self::num($k[3]), 'max_humidity' => self::num($k[4])])->values()->all();
    }

    public function sensor(?IotSensor $s): array
    {
        $manage = self::canManage();
        $tz = $this->tz();
        $base = ['id' => $s?->id, 'number' => $s?->number, 'kinds' => self::kindDefaults(), 'connections' => self::choices(IotSensor::CONNECTIONS),
            'branches' => self::choices(Branch::where('is_active', true)->orderBy('code')->pluck('name', 'id')->all()),
            'assets' => self::choices(MaintenanceAsset::whereNull('archived_at')->where('owner', 'own')->where('status', '<>', 'retired')->orderBy('number')->get()
                ->mapWithKeys(fn ($a) => [$a->id => "{$a->number} {$a->name}"])->all()),
            'accounts' => IotConnection::orderBy('name')->get()->map(fn ($c) => ['id' => $c->id, 'label' => $c->name, 'provider' => $c->provider])->all(),
            'values' => ['name' => (string) $s?->name, 'kind' => $s?->kind ?? 'fridge',
                'branch_id' => (string) ($s?->branch_id ?? Branch::where('is_active', true)->orderByDesc('is_hq')->orderBy('code')->value('id')),
                'asset_id' => (string) $s?->asset_id, 'min_temp' => $s ? self::num($s->min_temp) : '2', 'max_temp' => $s ? self::num($s->max_temp) : '8',
                'min_humidity' => self::num($s?->min_humidity), 'max_humidity' => self::num($s?->max_humidity), 'delay_minutes' => (string) ($s?->delay_minutes ?? 10),
                'silent_minutes' => (string) ($s?->silent_minutes ?? 30), 'connection' => $s?->connection ?? 'direct', 'connection_id' => (string) $s?->connection_id,
                'external_id' => (string) $s?->external_id],
            'actions' => ['edit' => $s ? $manage : IotSensorResource::canCreate()]];
        if (! $s) {
            return $base;
        }
        $direct = $s->connection === 'direct';

        return array_replace($base, ['status_label' => IotSensor::STATUSES[$s->status] ?? $s->status, 'tone' => self::STATUS_TONE[$s->status] ?? 'subtle',
            'reading' => self::reading($s), 'range' => $s->range(), 'last' => $s->last_reading_at?->toIso8601String(), 'last_ago' => $s->last_reading_at?->diffForHumans(),
            'battery' => $s->battery, 'temperature' => $s->measuresTemperature(), 'min' => $s->min_temp === null ? null : (float) $s->min_temp,
            'max' => $s->max_temp === null ? null : (float) $s->max_temp,
            'chart' => IotReading::where('sensor_id', $s->id)->where('read_at', '>=', now()->subDay())->whereNotNull('temperature')->orderBy('read_at')->get(['read_at', 'temperature'])
                ->map(fn ($r) => ['at' => $r->read_at->toIso8601String(), 'value' => (float) $r->temperature])->all(),
            'archive' => (new CompanyScreen($this->user))->archiveOf($s),
            'actions' => $base['actions'] + ['check' => $s->measuresTemperature() && self::canRespond(), 'address' => $direct && $manage, 'new_address' => $direct && $manage,
                'acknowledge' => self::canRespond()],
            'alarms' => $this->alarmRows(IotAlarm::where('sensor_id', $s->id)->with('sensor')->orderByDesc('started_at')->limit(100)->get()),
            'checks' => $s->measuresTemperature() ? $s->checks()->orderByDesc('checked_at')->limit(100)->get()->map(fn ($c) => ['id' => $c->id,
                'when' => $c->checked_at?->timezone($tz)->format('j M Y H:i'), 'by' => $c->user_name, 'seen' => $c->seen_temp === null ? null : self::num($c->seen_temp).' °C',
                'ok' => (bool) $c->ok, 'note' => $c->note])->all() : []]);
    }

    /** Where a direct sensor sends its readings (only for people who manage sensors). */
    public function address(IotSensor $s): array
    {
        return ['url' => route('iot.reading', ['token' => $s->token])];
    }

    // -------------------------------------------------------------------------------------------- Alarms

    private function alarmRows(iterable $alarms): array
    {
        $tz = $this->tz();
        $orders = MaintenanceOrderResource::canViewAny();
        $respond = self::canRespond();

        return collect($alarms)->map(fn (IotAlarm $a) => ['id' => $a->id, 'number' => $a->number, 'sensor' => $a->sensor?->name, 'sensor_id' => $a->sensor_id,
            'kind' => IotAlarm::KINDS[$a->kind] ?? $a->kind, 'worst' => $a->worst === null ? null : self::num($a->worst).($a->kind === 'humidity' ? ' %' : ' °C'),
            'from' => $a->started_at?->timezone($tz)->format('j M H:i'),
            'for' => ($a->ended_at ? 'for ' : 'going on, ').($a->minutes() >= 60 ? intdiv($a->minutes(), 60).' h '.($a->minutes() % 60).' min' : $a->minutes().' min'),
            'status' => $a->status, 'status_label' => IotAlarm::STATUSES[$a->status] ?? $a->status, 'tone' => self::ALARM_TONE[$a->status] ?? 'subtle',
            'action' => $a->action ? (IotAlarm::ACTIONS[$a->action] ?? $a->action) : null,
            'by' => trim(($a->acknowledged_by_name ? $a->acknowledged_by_name.', '.$a->acknowledged_at?->timezone($tz)->format('j M H:i') : '').($a->notes ? ' — '.$a->notes : ''), ' —') ?: null,
            'order' => $a->maintenance_order_id ? ['number' => $a->order?->number, 'open' => $orders ? "/office/maintenance/orders/{$a->maintenance_order_id}" : null] : null,
            'can_acknowledge' => $a->status !== 'closed' && $respond])->values()->all();
    }

    /** @param  array<string, mixed>  $p  open (1 by default: not closed; 0 closed; all), page, per */
    public function alarms(array $p): array
    {
        $q = IotSensorResource::scoped(IotAlarm::query())->with(['sensor', 'order']);
        $open = (string) ($p['open'] ?? '1');
        match ($open) {
            '1' => $q->where('status', '<>', 'closed'), '0' => $q->where('status', 'closed'), default => $open = 'all',
        };
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();

        return ['total' => $total, 'page' => $page, 'per' => $per, 'open' => $open, 'actions' => self::choices(IotAlarm::ACTIONS),
            'rows' => $this->alarmRows($q->orderByDesc('started_at')->forPage($page, $per)->get())];
    }

    // -------------------------------------------------------------------------------------------- Temperature log

    public function log(?string $sensor, ?string $month): array
    {
        $page = new TemperatureLog;
        $page->sensor = $sensor;
        $page->month = is_string($month) ? $month : '';
        $page->mount();
        $sensors = $page->sensors();
        $d = $page->data();
        $tz = $this->tz();
        $base = ['sensors' => self::choices($sensors), 'sensor' => $page->sensor, 'month' => $page->month, 'this_month' => now($tz)->format('Y-m'),
            'company' => $this->user->tenant?->name];
        if (! $d) {
            return $base + ['log' => null];
        }
        $s = $d['sensor'];
        $hi = $s->max_temp === null ? null : (float) $s->max_temp;
        $lo = $s->min_temp === null ? null : (float) $s->min_temp;

        return $base + ['log' => ['name' => $s->name, 'number' => $s->number, 'branch' => $s->branch?->name, 'period' => $d['from']->format('F Y'), 'range' => $s->range(),
            'lowest' => $d['min'] !== null ? (float) $d['min'].' °C' : '—', 'highest' => $d['max'] !== null ? (float) $d['max'].' °C' : '—',
            'out' => intdiv($d['out'], 60).' h '.($d['out'] % 60).' min', 'alarm_count' => $d['alarms']->count(),
            'days' => collect($d['rows'])->map(function ($r) use ($hi, $lo, $tz) {
                $day = $r['day'];

                return ['date' => $r['date']->format('D j'), 'min' => $day?->min_temp !== null ? (float) $day->min_temp.' °C' : '—',
                    'max' => $day?->max_temp !== null ? (float) $day->max_temp.' °C' : '—', 'avg' => $day?->average() !== null ? $day->average().' °C' : '—',
                    'out' => $day && $day->out_minutes ? $day->out_minutes.' min' : '',
                    'bad' => $day && (($hi !== null && (float) $day->max_temp > $hi) || ($lo !== null && $day->min_temp !== null && (float) $day->min_temp < $lo)),
                    'checks' => collect($r['checks'])->map(fn ($c) => $c->checked_at->timezone($tz)->format('H:i').' · '.($c->seen_temp !== null ? (float) $c->seen_temp.' °C' : '—')
                        .' · '.$c->user_name.($c->ok ? '' : ' · not well').($c->note ? ' — '.$c->note : ''))->values()->all()];
            })->all(),
            'alarms' => $d['alarms']->map(fn (IotAlarm $a) => ['id' => $a->id, 'number' => $a->number, 'kind' => IotAlarm::KINDS[$a->kind] ?? $a->kind,
                'from' => $a->started_at->timezone($tz)->format('j M H:i'), 'until' => $a->ended_at ? $a->ended_at->timezone($tz)->format('j M H:i') : 'still going on',
                'worst' => $a->worst !== null ? (float) $a->worst.($a->kind === 'humidity' ? ' %' : ' °C') : '—',
                'done' => ($a->action ? (IotAlarm::ACTIONS[$a->action] ?? $a->action) : 'Not yet said').($a->notes ? ' — '.$a->notes : ''),
                'by' => trim($a->acknowledged_by_name.($a->acknowledged_at ? ', '.$a->acknowledged_at->timezone($tz)->format('j M H:i') : ''))])->values()->all()]];
    }

    // -------------------------------------------------------------------------------------------- Sensor clouds

    public function clouds(): array
    {
        return ['providers' => self::choices(IotConnection::PROVIDERS), 'regions' => self::choices(IotConnection::TUYA_REGIONS),
            'rows' => IotConnection::orderBy('name')->get()->map(fn (IotConnection $c) => ['id' => $c->id, 'name' => $c->name,
                'provider' => IotConnection::PROVIDERS[$c->provider] ?? $c->provider, 'sensors' => IotSensor::where('connection_id', $c->id)->count(),
                'last' => $c->last_polled_at ? CarbonImmutable::parse($c->last_polled_at)->diffForHumans() : null, 'problem' => $c->last_error, 'active' => (bool) $c->is_active,
                // Only what is safe to show again: never the password or secret (left empty keeps them).
                'values' => ['provider' => $c->provider, 'name' => $c->name, 'region' => (string) $c->region, 'is_active' => (bool) $c->is_active,
                    'email' => (string) ($c->credentials['email'] ?? ''), 'client_id' => (string) ($c->credentials['client_id'] ?? ''), 'password' => '', 'secret' => '']])->all()];
    }
}
