<?php

namespace App\Domain\Iot;

use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\IotConnection;
use App\Models\IotSensor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sensor clouds (paid add-on "iot"): for sensors that report to their maker's cloud rather than to ZionEX. Every few
 * minutes the readings since the last time are fetched and recorded like any other:
 *   - SensorPush: the account's e-mail and password (gateway needed); temperatures arrive in °F and are converted.
 *   - Tuya / Smart Life (many low-cost Wi-Fi sensors): a cloud project's Access ID and Secret, in the data centre of the
 *     app account the sensors are linked to; each sensor's current status is read (codes va_temperature / temp_current
 *     in tenths of a degree, va_humidity / humidity_value, doorcontact_state, battery_percentage).
 * Credentials are kept encrypted.
 */
final class Clouds
{
    public const SENSORPUSH = 'https://api.sensorpush.com/api/v1';

    public const POLL_MINUTES = 5;

    public function __construct(private readonly Recorder $recorder, private readonly Sensors $sensors) {}

    public function save(Actor $actor, IotConnection $c, array $d): IotConnection
    {
        $this->sensors->require($actor, 'iot.manage');
        $provider = $d['provider'] ?? $c->provider;
        if (! isset(IotConnection::PROVIDERS[$provider])) {
            throw new DomainException('Choose the sensor cloud.');
        }
        $creds = array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : $v, (array) ($d['credentials'] ?? [])), fn ($v) => $v !== '' && $v !== null);
        $creds = $creds + ($c->exists ? ($c->credentials ?? []) : []); // a blank secret on change keeps the one kept
        $needed = $provider === 'sensorpush' ? ['email', 'password'] : ['client_id', 'secret'];
        if (array_diff($needed, array_keys($creds))) {
            throw new DomainException($provider === 'sensorpush' ? 'Enter the SensorPush account’s e-mail and password.' : 'Enter the Tuya project’s Access ID and Access Secret.');
        }
        if ($provider === 'tuya' && ! isset(IotConnection::TUYA_REGIONS[$d['region'] ?? $c->region ?? ''])) {
            throw new DomainException('Choose the Tuya data centre of the app account the sensors are linked to.');
        }
        $c->forceFill(['tenant_id' => $actor->tenantId, 'provider' => $provider, 'name' => mb_substr(trim((string) ($d['name'] ?? $c->name)) ?: IotConnection::PROVIDERS[$provider], 0, 255),
            'credentials' => array_intersect_key($creds, array_flip($needed)), 'region' => $provider === 'tuya' ? ($d['region'] ?? $c->region) : null,
            'is_active' => (bool) ($d['is_active'] ?? $c->is_active ?? true)]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $c, $c->exists ? 'iot_connection.changed' : 'iot_connection.added'));
    }

    /** Accounts not read for a while. */
    public function pollDue(string $tenantId): int
    {
        $n = 0;
        foreach (IotConnection::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('last_polled_at')->orWhere('last_polled_at', '<=', now()->subMinutes(self::POLL_MINUTES)))->get() as $c) {
            $n += $this->poll($c);
        }

        return $n;
    }

    /** Fetch and record the readings of the account's sensors; the error is kept on the account (shown on its page). */
    public function poll(IotConnection $c): int
    {
        $sensors = IotSensor::withoutGlobalScopes()->where('connection_id', $c->id)->where('is_active', true)->get()->keyBy('external_id');
        $n = 0;
        try {
            $readings = $sensors->isEmpty() ? [] : ($c->provider === 'sensorpush' ? $this->sensorPush($c, $sensors->keys()->all()) : $this->tuya($c, $sensors->keys()->all()));
            foreach ($readings as [$id, $r]) {
                if (($s = $sensors[$id] ?? null) && $this->sensors->record($s, $r)) {
                    $n++;
                }
            }
            $c->forceFill(['last_polled_at' => now(), 'last_error' => null])->save();
        } catch (Throwable $e) {
            $c->forceFill(['last_polled_at' => now(), 'last_error' => Str::limit($e->getMessage(), 490)])->save();
        }

        return $n;
    }

    /** @return list<array{0: string, 1: array}> [device id, reading] */
    private function sensorPush(IotConnection $c, array $ids): array
    {
        $auth = Http::timeout(20)->post(self::SENSORPUSH.'/oauth/authorize', ['email' => $c->credentials['email'], 'password' => $c->credentials['password']])->throw()->json('authorization');
        $token = Http::timeout(20)->post(self::SENSORPUSH.'/oauth/accesstoken', ['authorization' => $auth])->throw()->json('accesstoken');
        $since = CarbonImmutable::parse($c->last_polled_at ?? now()->subHour())->subMinutes(2);
        $data = Http::timeout(30)->withHeaders(['Authorization' => $token])->post(self::SENSORPUSH.'/samples', ['limit' => 50, 'sensors' => $ids,
            'startTime' => $since->toIso8601ZuluString()])->throw()->json('sensors') ?? [];
        $out = [];
        foreach ($data as $id => $samples) {
            foreach ((array) $samples as $x) {
                $out[] = [(string) $id, ['read_at' => isset($x['observed']) ? CarbonImmutable::parse($x['observed']) : null,
                    'temperature' => isset($x['temperature']) ? round(((float) $x['temperature'] - 32) * 5 / 9, 1) : null,
                    'humidity' => isset($x['humidity']) ? (int) round((float) $x['humidity']) : null, 'power_on' => null, 'door_open' => null, 'battery' => null]];
            }
        }

        return $out;
    }

    /** @return list<array{0: string, 1: array}> */
    private function tuya(IotConnection $c, array $ids): array
    {
        $base = 'https://openapi.tuya'.$c->region.'.com';
        $token = $this->tuyaCall($c, $base, '/v1.0/token?grant_type=1', null)['access_token'] ?? throw new DomainException('Tuya did not give an access token.');
        $out = [];
        foreach ($ids as $id) {
            $status = collect($this->tuyaCall($c, $base, '/v1.0/devices/'.rawurlencode($id).'/status', $token))->pluck('value', 'code');
            $tenths = fn ($v) => $v === null ? null : round((float) $v / 10, 1);
            $out[] = [$id, [
                'read_at' => null,
                'temperature' => $tenths($status['va_temperature'] ?? $status['temp_current'] ?? null),
                'humidity' => isset($status['va_humidity']) || isset($status['humidity_value']) ? (int) ($status['va_humidity'] ?? $status['humidity_value']) : null,
                'power_on' => null,
                'door_open' => isset($status['doorcontact_state']) ? (bool) $status['doorcontact_state'] : null,
                'battery' => isset($status['battery_percentage']) ? (int) $status['battery_percentage'] : null,
            ]];
        }

        return $out;
    }

    /** A signed Tuya OpenAPI GET (HMAC-SHA256 over the request, as Tuya documents). */
    private function tuyaCall(IotConnection $c, string $base, string $path, ?string $token): array
    {
        $t = (string) (int) round(microtime(true) * 1000);
        $nonce = Str::uuid()->toString();
        $stringToSign = "GET\n".hash('sha256', '')."\n\n".$path;
        $sign = strtoupper(hash_hmac('sha256', $c->credentials['client_id'].($token ?? '').$t.$nonce.$stringToSign, $c->credentials['secret']));
        $headers = ['client_id' => $c->credentials['client_id'], 'sign' => $sign, 't' => $t, 'nonce' => $nonce, 'sign_method' => 'HMAC-SHA256'] + ($token ? ['access_token' => $token] : []);
        $json = Http::timeout(20)->withHeaders($headers)->get($base.$path)->throw()->json();
        if (! ($json['success'] ?? false)) {
            throw new DomainException('Tuya: '.($json['msg'] ?? 'request refused').' ('.($json['code'] ?? '?').')');
        }

        return (array) ($json['result'] ?? []);
    }
}
