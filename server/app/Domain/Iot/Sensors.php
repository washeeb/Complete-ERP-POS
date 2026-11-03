<?php

namespace App\Domain\Iot;

use App\Domain\Licensing\LicenseManager;
use App\Domain\Messaging\Messenger;
use App\Domain\Messaging\Notifier;
use App\Domain\Messaging\Templates;
use App\Domain\Service\Maintenance;
use App\Domain\Service\ServiceDesk;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\IotAlarm;
use App\Models\IotCheck;
use App\Models\IotDay;
use App\Models\IotReading;
use App\Models\IotSensor;
use App\Models\MaintenanceAsset;
use App\Models\Tenant;
use App\Models\User;
use App\Security\Authorizer;
use App\Support\NotifyPrefs;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sensors (paid add-on "iot"; interface stage 5).
 *
 * A reading comes from the sensor itself (it posts to its own secret address: any Wi-Fi or LoRa device — Shelly,
 * Tasmota / ESP32, The Things Network…) or from a sensor cloud read every few minutes (Clouds). Each is kept (the log),
 * added to the day's summary, and compared with the sensor's range: out of range for longer than its delay (a door
 * opened to take out a vial is not an alarm), power off, a door left open, or no reading for too long (silent) opens
 * an alarm. People on duty for the branch are told at once — bell, WhatsApp or SMS — and again every 15 minutes until
 * someone acknowledges it; after 30 minutes the managers are told. Fridges, cold rooms and freezers with an equipment
 * record get a breakdown work order (service add-on). Someone says what was done (stock checked, moved, discarded…);
 * the alarm closes when that is said and the reading is back in range. People also sign daily checks of what the fridge
 * display showed. Everything appears in the monthly temperature log for inspections.
 */
final class Sensors
{
    public const ADDON = 'iot';

    public const REPEAT_MINUTES = 15;

    public const ESCALATE_MINUTES = 30;

    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly LicenseManager $license,
        private readonly Notifier $notifier,
    ) {}

    public static function enabled(): bool
    {
        return app(LicenseManager::class)->hasAddon(self::ADDON);
    }

    // --- set-up ----------------------------------------------------------------------------------------------------------

    public function save(Actor $actor, IotSensor $s, array $d): IotSensor
    {
        $branch = Branch::find($d['branch_id'] ?? $s->branch_id) ?? throw new DomainException('Choose the branch the sensor is at.');
        $this->require($actor, 'iot.manage', $branch);
        $kind = $d['kind'] ?? $s->kind ?? 'fridge';
        if (! isset(IotSensor::KINDS[$kind])) {
            throw new DomainException('Choose what the sensor watches.');
        }
        $name = trim((string) ($d['name'] ?? $s->name));
        if ($name === '') {
            throw new DomainException('Name the sensor (e.g. “Vaccine fridge, dispensary”).');
        }
        $connection = $d['connection'] ?? $s->connection ?? 'direct';
        if (! isset(IotSensor::CONNECTIONS[$connection])) {
            throw new DomainException('Choose how the sensor reaches ZionEX.');
        }
        if ($connection !== 'direct' && (! ($d['connection_id'] ?? $s->connection_id) || trim((string) ($d['external_id'] ?? $s->external_id)) === '')) {
            throw new DomainException('Choose the sensor cloud account and the sensor’s id in it.');
        }
        [, $min, $max, $minH, $maxH] = IotSensor::KINDS[$kind];
        $num = fn (string $k, $default) => array_key_exists($k, $d) ? ($d[$k] === '' || $d[$k] === null ? null : $d[$k]) : ($s->exists ? $s->{$k} : $default);
        $lo = $num('min_temp', $min);
        $hi = $num('max_temp', $max);
        if ($lo !== null && $hi !== null && (float) $lo >= (float) $hi) {
            throw new DomainException('The lowest temperature must be below the highest.');
        }
        $asset = ($d['asset_id'] ?? null) ? MaintenanceAsset::find($d['asset_id']) : null;

        return $this->recorder->transaction(function () use ($actor, $s, $d, $branch, $kind, $name, $connection, $num, $lo, $hi, $minH, $maxH, $asset) {
            $isNew = ! $s->exists;
            $s->forceFill([
                'name' => mb_substr($name, 0, 255), 'kind' => $kind, 'branch_id' => $branch->id, 'asset_id' => array_key_exists('asset_id', $d) ? $asset?->id : $s->asset_id,
                'connection' => $connection, 'connection_id' => $connection === 'direct' ? null : ($d['connection_id'] ?? $s->connection_id),
                'external_id' => $connection === 'direct' ? null : trim((string) ($d['external_id'] ?? $s->external_id)),
                'min_temp' => $lo, 'max_temp' => $hi, 'min_humidity' => $num('min_humidity', $minH), 'max_humidity' => $num('max_humidity', $maxH),
                'delay_minutes' => max(0, min(240, (int) $num('delay_minutes', $kind === 'power' ? 5 : 10))),
                'silent_minutes' => max(5, min(1440, (int) $num('silent_minutes', 30))),
                'is_active' => (bool) ($d['is_active'] ?? $s->is_active ?? true),
            ]);
            if (! $s->is_active) {
                $s->status = 'off';
            } elseif ($s->status === 'off') {
                $s->status = $s->last_reading_at ? 'ok' : 'waiting';
            }
            if ($isNew) {
                $s->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'iot_sensor'), 'status' => 'waiting',
                    'token' => $connection === 'direct' ? Str::random(40) : null]);
            } elseif ($connection === 'direct' && ! $s->token) {
                $s->token = Str::random(40);
            }

            return $this->recorder->save($actor, $s, $isNew ? 'iot_sensor.added' : 'iot_sensor.changed', branchId: $branch->id);
        });
    }

    /** A new secret address (the old one stops working), e.g. when a device is replaced or the address was shared. */
    public function newToken(Actor $actor, IotSensor $s): IotSensor
    {
        $this->require($actor, 'iot.manage', $s->branch);
        $s->token = Str::random(40);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $s, 'iot_sensor.new_address'));
    }

    // --- readings --------------------------------------------------------------------------------------------------------

    /**
     * What a device sent, in whatever usual shape: {temperature, humidity, power, door, battery, time}, short names
     * (temp, hum, t, h), Shelly's ?temp=&hum=, Tasmota's {"StatusSNS": {"AM2301": {"Temperature": …}}} or The Things
     * Network's {"uplink_message": {"decoded_payload": {…}}}.
     *
     * @return array{read_at: ?CarbonImmutable, temperature: ?float, humidity: ?int, power_on: ?bool, door_open: ?bool, battery: ?int}
     */
    public static function parse(array $p): array
    {
        $flat = [];
        array_walk_recursive($p, function ($v, $k) use (&$flat) {
            $flat[strtolower((string) $k)] ??= $v;
        });
        $pick = function (array $names) use ($flat) {
            foreach ($names as $n) {
                if (array_key_exists($n, $flat) && $flat[$n] !== '' && $flat[$n] !== null) {
                    return $flat[$n];
                }
            }

            return null;
        };
        $bool = fn ($v) => $v === null ? null : (is_bool($v) ? $v : in_array(strtolower((string) $v), ['1', 'true', 'on', 'open', 'yes'], true));
        $temp = $pick(['temperature', 'temp', 'tc', 'temperature_c', 't', 'va_temperature']);
        $hum = $pick(['humidity', 'hum', 'rh', 'h', 'relative_humidity', 'va_humidity']);
        $time = $pick(['time', 'timestamp', 'ts', 'received_at', 'observed', 'read_at']);
        $at = null;
        if ($time !== null) {
            try {
                $at = is_numeric($time) ? CarbonImmutable::createFromTimestamp((int) ((float) $time > 1e12 ? $time / 1000 : $time)) : CarbonImmutable::parse((string) $time);
            } catch (Throwable) {
                $at = null;
            }
        }
        $door = $pick(['door', 'door_open', 'contact', 'doorcontact_state', 'open']);

        return [
            'read_at' => $at,
            'temperature' => is_numeric($temp) ? round((float) $temp, 1) : null,
            'humidity' => is_numeric($hum) ? (int) round((float) $hum) : null,
            'power_on' => $bool($pick(['power', 'power_on', 'mains', 'ac'])),
            'door_open' => $door === null ? null : (in_array(strtolower((string) $door), ['closed', 'shut'], true) ? false : $bool($door)),
            'battery' => is_numeric($b = $pick(['battery', 'bat', 'battery_percentage', 'batt'])) ? max(0, min(100, (int) $b)) : null,
        ];
    }

    /** A direct sensor posted to its address: the token names it. */
    public function receive(string $token, array $payload): ?IotSensor
    {
        $s = IotSensor::withoutGlobalScopes()->where('token', $token)->first();
        if (! $s || ! $s->is_active || ! self::enabled()) {
            return null;
        }
        app(TenantContext::class)->set($s->tenant_id);
        $this->record($s, self::parse($payload));

        return $s;
    }

    /** Keep a reading and act on it. Readings from the future are taken as now; repeats are kept once. */
    public function record(IotSensor $s, array $r): ?IotReading
    {
        $at = ($r['read_at'] ?? null) instanceof CarbonImmutable ? $r['read_at'] : CarbonImmutable::now();
        if ($at->isFuture()) {
            $at = CarbonImmutable::now();
        }
        if (($r['temperature'] ?? null) === null && ($r['humidity'] ?? null) === null && ($r['power_on'] ?? null) === null && ($r['door_open'] ?? null) === null) {
            return null; // nothing we can use (a battery report alone, a test ping)
        }

        return DB::transaction(function () use ($s, $r, $at) {
            $s = IotSensor::withoutGlobalScopes()->lockForUpdate()->find($s->id);
            if (IotReading::withoutGlobalScopes()->where('sensor_id', $s->id)->where('read_at', $at)->exists()) {
                return null;
            }
            $reading = IotReading::create(['tenant_id' => $s->tenant_id, 'sensor_id' => $s->id, 'read_at' => $at, 'temperature' => $r['temperature'] ?? null,
                'humidity' => $r['humidity'] ?? null, 'power_on' => $r['power_on'] ?? null, 'door_open' => $r['door_open'] ?? null, 'battery' => $r['battery'] ?? null]);
            $previous = $s->last_reading_at;
            $out = $this->outOfRange($s, $reading);
            $this->day($s, $reading, $out, $previous);
            if (! $previous || $at->gte($previous)) { // a late, older reading goes in the log without moving the sensor back in time
                $s->forceFill(['last_reading_at' => $at, 'last_temp' => $reading->temperature ?? $s->last_temp, 'last_humidity' => $reading->humidity ?? $s->last_humidity,
                    'last_power' => $reading->power_on ?? $s->last_power, 'last_door_open' => $reading->door_open ?? $s->last_door_open,
                    'battery' => $reading->battery ?? $s->battery]);
                $this->evaluate($s, $out, $at, $reading);
            }
            $s->save();

            return $reading;
        });
    }

    /** @return array{0: string, 1: float}|null what is wrong with this reading, and the value */
    private function outOfRange(IotSensor $s, IotReading $r): ?array
    {
        $t = $r->temperature === null ? null : (float) $r->temperature;

        return match (true) {
            $r->power_on === false => ['power', 0.0],
            $r->door_open === true && $s->kind !== 'power' => ['door', 1.0],
            $t !== null && $s->max_temp !== null && $t > (float) $s->max_temp => ['too_warm', $t],
            $t !== null && $s->min_temp !== null && $t < (float) $s->min_temp => ['too_cold', $t],
            $r->humidity !== null && (($s->max_humidity !== null && $r->humidity > $s->max_humidity) || ($s->min_humidity !== null && $r->humidity < $s->min_humidity)) => ['humidity', (float) $r->humidity],
            default => null,
        };
    }

    private function evaluate(IotSensor $s, ?array $out, CarbonImmutable $at, IotReading $reading): void
    {
        // Reporting again: a silent alarm ends.
        $this->end($s, ['silent'], $at);
        if (! $out) {
            $s->forceFill(['out_since' => null, 'out_kind' => null]);
            $this->end($s, array_keys(array_diff_key(IotAlarm::KINDS, ['silent' => 1])), $at);
            $s->status = $s->is_active ? (IotAlarm::withoutGlobalScopes()->where('sensor_id', $s->id)->where('status', '<>', 'closed')->whereNull('ended_at')->exists() ? 'alarm' : 'ok') : 'off';

            return;
        }
        [$kind, $value] = $out;
        if ($s->out_kind !== $kind || ! $s->out_since) {
            $s->forceFill(['out_since' => $at, 'out_kind' => $kind]);
        }
        $open = $this->openAlarm($s, $kind);
        if ($open) {
            $worse = $kind === 'too_cold' ? $value < (float) $open->worst : $value > (float) $open->worst;
            if ($open->worst === null || $worse) {
                $open->worst = $value;
                $open->save();
            }
            $s->status = 'alarm';

            return;
        }
        if ($s->out_since->diffInMinutes($at) >= $s->delay_minutes) {
            $this->raise($s, $kind, $s->out_since, $value);
        } elseif ($s->status !== 'alarm') {
            $s->status = 'ok'; // out of range, but not long enough yet
        }
    }

    private function day(IotSensor $s, IotReading $r, ?array $out, ?CarbonInterface $previous): void
    {
        $tz = Tenant::find($s->tenant_id)?->timezone ?: 'UTC';
        $day = $r->read_at->copy()->timezone($tz)->toDateString();
        $d = IotDay::withoutGlobalScopes()->firstOrNew(['sensor_id' => $s->id, 'day' => $day], ['tenant_id' => $s->tenant_id]);
        $t = $r->temperature === null ? null : (float) $r->temperature;
        if ($t !== null) {
            $d->min_temp = $d->min_temp === null ? $t : min((float) $d->min_temp, $t);
            $d->max_temp = $d->max_temp === null ? $t : max((float) $d->max_temp, $t);
            $d->sum_temp = (float) $d->sum_temp + $t;
            $d->readings = (int) $d->readings + 1;
        }
        if ($r->humidity !== null) {
            $d->min_humidity = $d->min_humidity === null ? $r->humidity : min($d->min_humidity, $r->humidity);
            $d->max_humidity = $d->max_humidity === null ? $r->humidity : max($d->max_humidity, $r->humidity);
        }
        if ($out && $previous) {
            $d->out_minutes = (int) $d->out_minutes + min(60, max(0, (int) $previous->diffInMinutes($r->read_at)));
        }
        $d->save();
    }

    // --- alarms ----------------------------------------------------------------------------------------------------------

    private function openAlarm(IotSensor $s, string $kind): ?IotAlarm
    {
        return IotAlarm::withoutGlobalScopes()->where('sensor_id', $s->id)->where('kind', $kind)->whereNull('ended_at')->latest('started_at')->first();
    }

    private function raise(IotSensor $s, string $kind, CarbonInterface $since, ?float $worst): IotAlarm
    {
        // The worst reading since it went out of range, not only the one that tipped it into an alarm.
        $readings = IotReading::withoutGlobalScopes()->where('sensor_id', $s->id)->where('read_at', '>=', $since);
        $worst = match ($kind) {
            'too_warm' => max($worst ?? -INF, (float) ($readings->max('temperature') ?? -INF)),
            'too_cold' => min($worst ?? INF, (float) ($readings->min('temperature') ?? INF)),
            default => $worst,
        };
        $worst = is_infinite((float) $worst) ? null : $worst;
        $alarm = IotAlarm::create(['tenant_id' => $s->tenant_id, 'number' => $this->numbers->next($s->tenant_id, 'iot_alarm'), 'sensor_id' => $s->id, 'branch_id' => $s->branch_id,
            'kind' => $kind, 'started_at' => $since, 'worst' => $worst, 'status' => 'open']);
        $s->status = 'alarm';
        $this->recorder->audit->record(Actor::system($s->tenant_id, 'Sensors', 'iot'), 'iot_alarm.raised', $alarm, ['kind' => [null, $kind], 'worst' => [null, $worst]], null, $s->branch_id);
        // A fridge, cold room or freezer that is too warm, too cold or without power: the equipment needs looking at.
        if ($s->asset_id && in_array($s->kind, ['fridge', 'cold_room', 'freezer'], true) && in_array($kind, ['too_warm', 'too_cold', 'power'], true) && ServiceDesk::enabled()) {
            try {
                $order = app(Maintenance::class)->breakdown($s->tenant_id, $s->asset_id, $this->title($s, $alarm), "Alarm {$alarm->number} from sensor {$s->number}.");
                $alarm->maintenance_order_id = $order->id;
                $alarm->save();
            } catch (Throwable $e) {
                report($e); // the alarm itself must still go out
            }
        }
        $this->alert($alarm, $s, false);

        return $alarm;
    }

    /** End alarms of these kinds (back in range); one already acknowledged is closed. */
    private function end(IotSensor $s, array $kinds, CarbonInterface $at): void
    {
        foreach (IotAlarm::withoutGlobalScopes()->where('sensor_id', $s->id)->whereIn('kind', $kinds)->whereNull('ended_at')->get() as $a) {
            $a->forceFill(['ended_at' => $at, 'status' => $a->status === 'acknowledged' ? 'closed' : $a->status])->save();
        }
    }

    /**
     * Every minute: silent sensors, alarms whose delay has passed without a new reading, reminders until someone
     * acknowledges, the managers after half an hour.
     */
    public function watch(string $tenantId): array
    {
        $silent = $late = $reminded = $escalated = 0;
        foreach (IotSensor::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_active', true)->get() as $s) {
            $since = $s->last_reading_at ?? $s->created_at;
            if ($since->copy()->addMinutes($s->silent_minutes)->isPast() && ! $this->openAlarm($s, 'silent')) {
                DB::transaction(function () use ($s, $since) {
                    $this->raise($s, 'silent', $since, null);
                    $s->save();
                });
                $silent++;
            } elseif ($s->out_since && $s->out_kind && ! $this->openAlarm($s, $s->out_kind) && $s->out_since->copy()->addMinutes($s->delay_minutes)->isPast()) {
                // Out of range, and no reading since to say so again: the delay has passed all the same.
                DB::transaction(function () use ($s) {
                    $this->raise($s, $s->out_kind, $s->out_since, $s->out_kind === 'humidity' ? (float) $s->last_humidity : (float) $s->last_temp);
                    $s->save();
                });
                $late++;
            }
        }
        foreach (IotAlarm::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('status', 'open')->with('sensor')->get() as $a) {
            if ($a->ended_at && $a->kind === 'silent') {
                continue; // reporting again: the reminder would only confuse
            }
            if (! $a->escalated_at && $a->created_at->copy()->addMinutes(self::ESCALATE_MINUTES)->isPast()) {
                $this->alert($a, $a->sensor, true);
                $escalated++;
            } elseif (! $a->last_alert_at || $a->last_alert_at->copy()->addMinutes(self::REPEAT_MINUTES)->isPast()) {
                $this->alert($a, $a->sensor, false);
                $reminded++;
            }
        }

        return ['silent' => $silent, 'late' => $late, 'reminded' => $reminded, 'escalated' => $escalated];
    }

    /** Someone says what was done; the reminders stop. Closed at once when the reading is already back in range. */
    public function acknowledge(Actor $actor, IotAlarm $a, string $action, ?string $notes = null): IotAlarm
    {
        $this->require($actor, 'iot.respond', Branch::find($a->branch_id));
        if ($a->status === 'closed') {
            throw new DomainException('This alarm is closed.');
        }
        if (! isset(IotAlarm::ACTIONS[$action])) {
            throw new DomainException('Say what was done.');
        }
        if (in_array($action, ['discarded', 'other'], true) && trim((string) $notes) === '') {
            throw new DomainException('Say what was '.($action === 'discarded' ? 'quarantined or discarded (product, batch, quantity)' : 'done').' in the notes.');
        }
        $a->forceFill(['status' => $a->ended_at ? 'closed' : 'acknowledged', 'action' => $action, 'notes' => $notes ? trim($notes) : null,
            'acknowledged_by_name' => $actor->name, 'acknowledged_at' => now()]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $a, 'iot_alarm.acknowledged', IotAlarm::ACTIONS[$action], $a->branch_id));
    }

    /** A signed check: what the fridge's own display showed. */
    public function check(Actor $actor, IotSensor $s, ?float $seen, bool $ok, ?string $note = null): IotCheck
    {
        $this->require($actor, 'iot.respond', $s->branch);
        if (! $ok && trim((string) $note) === '') {
            throw new DomainException('Say what is wrong.');
        }

        return $this->recorder->transaction(function () use ($actor, $s, $seen, $ok, $note) {
            $c = IotCheck::create(['tenant_id' => $s->tenant_id, 'sensor_id' => $s->id, 'user_id' => $actor->id, 'user_name' => $actor->name, 'checked_at' => now(),
                'seen_temp' => $seen, 'ok' => $ok, 'note' => $note ? mb_substr(trim($note), 0, 255) : null]);
            $this->recorder->audit->record($actor, 'iot_sensor.checked', $s, ['seen' => [null, $seen], 'ok' => [null, $ok]], $note, $s->branch_id);

            return $c;
        });
    }

    // --- telling people --------------------------------------------------------------------------------------------------

    /** People on duty for the branch (bell, WhatsApp / SMS by their choice); with $escalate the managers too. */
    private function alert(IotAlarm $a, IotSensor $s, bool $escalate): void
    {
        $title = ($a->alerts_sent ? 'Still: ' : '').$this->title($s, $a);
        $body = 'Since '.$a->started_at->timezone(Tenant::find($s->tenant_id)?->timezone ?: 'UTC')->format('H:i').". Acknowledge it with what you did ({$a->number}).";
        // People on duty: those who respond to sensors at this branch; escalated, also those who run the sensors.
        $branch = Branch::withoutGlobalScopes()->find($s->branch_id);
        $people = User::withoutGlobalScopes()->where('tenant_id', $s->tenant_id)->where('is_active', true)->get()
            ->filter(fn (User $u) => $this->authz->allows($u, 'iot.respond', $branch) || ($escalate && $this->authz->allowsAnywhere($u, 'iot.manage')));
        // Escalated: managers get "Nobody has acknowledged it", the others on duty the usual reminder (each person once).
        $managers = $escalate ? $people->filter(fn (User $u) => $this->authz->allowsAnywhere($u, 'iot.manage'))->pluck('id')->all() : [];
        $this->notifier->toPermission($s->tenant_id, 'iot.respond', $s->branch_id, $title, $body, url('/admin/iot-alarms'), $managers, 'iot');
        if ($escalate) {
            $this->notifier->toPermission($s->tenant_id, 'iot.manage', null, 'Nobody has acknowledged it: '.$title, $body, url('/admin/iot-alarms'), topic: 'iot');
        }
        $messenger = app(Messenger::class);
        foreach ($people as $u) {
            foreach (['whatsapp', 'sms'] as $channel) {
                $t = Templates::find($s->tenant_id, 'iot_alarm', $channel);
                if ($u->phone && $t?->is_active && NotifyPrefs::wants($u, 'iot', $channel) && $messenger->provider($s->tenant_id, $channel)) {
                    $messenger->later($s->tenant_id, $channel, $u->phone, $t, ['sensor' => $s->name, 'problem' => IotAlarm::KINDS[$a->kind], 'value' => $this->value($s, $a),
                        'since' => $a->started_at->timezone(Tenant::find($s->tenant_id)?->timezone ?: 'UTC')->format('H:i'), 'number' => $a->number], $a->number, 'Sensors');
                    break; // WhatsApp if it can, else SMS
                }
            }
        }
        $a->forceFill(['alerts_sent' => $a->alerts_sent + 1, 'last_alert_at' => now()] + ($escalate ? ['escalated_at' => now()] : []))->save();
    }

    private function title(IotSensor $s, IotAlarm $a): string
    {
        return IotAlarm::KINDS[$a->kind].": {$s->name}".(($v = $this->value($s, $a)) !== '' ? " ({$v})" : '');
    }

    private function value(IotSensor $s, IotAlarm $a): string
    {
        return match ($a->kind) {
            'too_warm', 'too_cold' => $a->worst !== null ? rtrim(rtrim(number_format((float) $a->worst, 1), '0'), '.').' °C, range '.$s->range() : '',
            'humidity' => $a->worst !== null ? ((int) $a->worst).' %' : '',
            'silent' => 'no reading since '.($s->last_reading_at?->timezone(Tenant::find($s->tenant_id)?->timezone ?: 'UTC')->format('j M H:i') ?? 'it was added'),
            default => '',
        };
    }

    // --- helpers ---------------------------------------------------------------------------------------------------------

    public function require(Actor $actor, string $permission, ?Branch $branch = null): void
    {
        $this->assertLicensed();
        if (! $actor->user || ! ($branch ? $this->authz->allows($actor->user, $permission, $branch) : $this->authz->allowsAnywhere($actor->user, $permission))) {
            throw new AccessDenied;
        }
    }

    public function assertLicensed(): void
    {
        if (! $this->license->hasAddon(self::ADDON)) {
            throw new DomainException('Sensors are a paid add-on: ask SyncLytics to add them to your licence.');
        }
    }
}
