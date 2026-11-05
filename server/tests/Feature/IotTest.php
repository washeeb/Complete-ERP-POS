<?php

use App\Domain\Iot\Clouds;
use App\Domain\Iot\Sensors;
use App\Domain\Service\Maintenance;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Pages\IotAlarms;
use App\Filament\Pages\IotClouds;
use App\Filament\Pages\TemperatureLog;
use App\Filament\Resources\IotSensors\IotSensorResource;
use App\Filament\Widgets\Panels\IotPanel;
use App\Models\IotAlarm;
use App\Models\IotConnection;
use App\Models\IotDay;
use App\Models\IotReading;
use App\Models\IotSensor;
use App\Models\MaintenanceAsset;
use App\Models\MaintenanceOrder;
use App\Models\Message;
use App\Models\MessageProvider;
use App\Security\ScopeType;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    // Mid-day and before anything is created: tests move the clock on by up to an hour and
    // readings are kept per day, and moving it back later would put the set-up in the future.
    $this->travelTo(now()->setTime(12, 0));
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    activateTestLicense(['features' => ['finance', 'hr', 'iot', 'service']]);
    $this->hq = $this->branches['HQ'];
    $this->sensors = app(Sensors::class);
    // The pharmacist on duty at the head office has a phone; texts go by SMS.
    $this->pharmacist = staff($this->owner, 'abena', 4, 'pharmacist', ScopeType::Branch, $this->hq->id);
    $this->pharmacist->forceFill(['phone' => '+233244000222'])->save();
    (new MessageProvider)->forceFill(['tenant_id' => $this->tenant->id, 'channel' => 'sms', 'provider' => 'webhook', 'credentials' => ['url' => 'https://sms.example.test'], 'is_active' => true])->save();
});

function fridge($t, array $d = []): IotSensor
{
    return app(Sensors::class)->save(actorFor($t->owner), new IotSensor, $d + ['name' => 'Vaccine fridge', 'kind' => 'fridge', 'branch_id' => $t->hq->id]);
}

function send($t, IotSensor $s, array $payload, string $method = 'post')
{
    return $method === 'get' ? $t->get(route('iot.reading', ['token' => $s->token]).'?'.http_build_query($payload)) : $t->postJson(route('iot.reading', ['token' => $s->token]), $payload);
}

it('is a paid add-on', function () {
    activateTestLicense(['features' => ['finance', 'hr']]);

    expect(fn () => fridge($this))->toThrow(DomainException::class, 'paid add-on');
    $this->actingAs(withMfa($this->owner), 'web')->get(IotSensorResource::getUrl())->assertForbidden();
});

it('sets up a sensor with its kind’s range and its own secret address', function () {
    $s = fridge($this);
    expect($s)->number->toMatch('/^SNS-/')->min_temp->toEqual('2.0')->max_temp->toEqual('8.0')->delay_minutes->toBe(10)->status->toBe('waiting')
        ->and(strlen($s->token))->toBe(40)
        ->and(fn () => fridge($this, ['min_temp' => 9, 'max_temp' => 8]))->toThrow(DomainException::class, 'below the highest')
        ->and(fn () => app(Sensors::class)->save(actorFor($this->pharmacist), new IotSensor, ['name' => 'x', 'kind' => 'room', 'branch_id' => $this->hq->id]))->toThrow(AccessDenied::class);

    $this->postJson(route('iot.reading', ['token' => str_repeat('x', 40)]), ['temperature' => 5])->assertNotFound();
});

it('understands the usual device formats', function () {
    expect(Sensors::parse(['temperature' => 4.56, 'humidity' => 51.4, 'battery' => 120]))->toMatchArray(['temperature' => 4.6, 'humidity' => 51, 'battery' => 100])
        ->and(Sensors::parse(['temp' => '3.2', 'hum' => '60'])['temperature'])->toBe(3.2)
        ->and(Sensors::parse(['StatusSNS' => ['Time' => '2026-11-03T10:00:00', 'AM2301' => ['Temperature' => 6.1, 'Humidity' => 48]]]))->toMatchArray(['temperature' => 6.1, 'humidity' => 48])
        ->and(Sensors::parse(['uplink_message' => ['decoded_payload' => ['temperature' => 7.0]], 'received_at' => '2026-11-03T09:00:00Z'])['temperature'])->toBe(7.0)
        ->and(Sensors::parse(['power' => 'off'])['power_on'])->toBeFalse()
        ->and(Sensors::parse(['door' => 'closed'])['door_open'])->toBeFalse()
        ->and(Sensors::parse(['door' => 'open'])['door_open'])->toBeTrue();
});

it('raises an alarm only after the delay, tells people on duty, reminds them and then the managers', function () {
    $asset = app(Maintenance::class)->saveAsset(actorFor($this->owner), new MaintenanceAsset, ['name' => 'Vaccine fridge', 'category' => 'fridge', 'branch_id' => $this->hq->id]);
    $s = fridge($this, ['asset_id' => $asset->id]);

    send($this, $s, ['temperature' => 5.0])->assertOk()->assertJson(['status' => 'ok']);
    $this->travel(2)->minutes();
    send($this, $s, ['temperature' => 9.5]); // the door opened: not yet an alarm
    $this->travel(3)->minutes();
    send($this, $s, ['temperature' => 6.0]);
    expect(IotAlarm::count())->toBe(0)->and($s->fresh()->status)->toBe('ok');

    $this->travel(1)->minutes();
    send($this, $s, ['temp' => 9.1], 'get');
    $this->travel(6)->minutes();
    send($this, $s, ['temperature' => 11.2]);
    $this->travel(5)->minutes();
    send($this, $s, ['temperature' => 10.4]); // 11 minutes warm
    $alarm = IotAlarm::sole();
    expect($alarm)->kind->toBe('too_warm')->status->toBe('open')->worst->toEqual('11.2')->alerts_sent->toBe(1)
        ->and($s->fresh()->status)->toBe('alarm')
        ->and($this->pharmacist->notifications()->where('data->title', 'like', 'Too warm: Vaccine fridge%')->exists())->toBeTrue()
        ->and(Message::where('reference', $alarm->number)->sole())->channel->toBe('sms')->to->toBe('+233244000222')
        ->and(MaintenanceOrder::sole())->kind->toBe('breakdown')->priority->toBe('urgent')->and($alarm->maintenance_order_id)->toBe(MaintenanceOrder::sole()->id);

    $this->travel(16)->minutes();
    expect($this->sensors->watch($this->tenant->id)['reminded'])->toBe(1);
    $this->travel(16)->minutes();
    expect($this->sensors->watch($this->tenant->id)['escalated'])->toBe(1)
        ->and($this->owner->notifications()->where('data->title', 'like', 'Nobody has acknowledged it%')->exists())->toBeTrue();

    // Someone acts; it closes when the reading is back in range.
    expect(fn () => $this->sensors->acknowledge(actorFor($this->pharmacist), $alarm->fresh(), 'discarded'))->toThrow(DomainException::class, 'quarantined or discarded');
    $this->sensors->acknowledge(actorFor($this->pharmacist), $alarm->fresh(), 'moved', 'Insulin and vaccines moved to fridge 2');
    expect($alarm->fresh()->status)->toBe('acknowledged')->and($this->sensors->watch($this->tenant->id)['reminded'])->toBe(0);
    send($this, $s, ['temperature' => 5.5]);
    expect($alarm->fresh())->status->toBe('closed')->ended_at->not->toBeNull()->and($s->fresh()->status)->toBe('ok');

    $day = IotDay::where('sensor_id', $s->id)->sole();
    expect($day)->min_temp->toEqual('5.0')->max_temp->toEqual('11.2')->readings->toBe(7)->and($day->out_minutes)->toBeGreaterThan(10);
});

it('finds silent sensors and alarms whose delay passed without a new reading, and keeps power and doors', function () {
    $s = fridge($this, ['silent_minutes' => 30]);
    send($this, $s, ['temperature' => 12.0]);
    $this->travel(11)->minutes();
    expect($this->sensors->watch($this->tenant->id))->toMatchArray(['late' => 1, 'silent' => 0]);
    $this->travel(25)->minutes();
    expect($this->sensors->watch($this->tenant->id)['silent'])->toBe(1);
    send($this, $s, ['temperature' => 4.0]);
    expect(IotAlarm::where('kind', 'silent')->sole()->ended_at)->not->toBeNull();

    $power = $this->sensors->save(actorFor($this->owner), new IotSensor, ['name' => 'Mains, dispensary', 'kind' => 'power', 'branch_id' => $this->hq->id]);
    send($this, $power, ['power' => 'off']);
    $this->travel(6)->minutes();
    send($this, $power, ['power' => 'off']);
    expect(IotAlarm::where('kind', 'power')->sole()->sensor_id)->toBe($power->id);
});

it('reads SensorPush and Tuya sensors from their clouds', function () {
    Http::fake([
        'api.sensorpush.com/api/v1/oauth/authorize' => Http::response(['authorization' => 'auth-1']),
        'api.sensorpush.com/api/v1/oauth/accesstoken' => Http::response(['accesstoken' => 'tok-1']),
        'api.sensorpush.com/api/v1/samples' => Http::response(['sensors' => ['sp-1' => [['observed' => now()->subMinutes(3)->toIso8601ZuluString(), 'temperature' => 41.0, 'humidity' => 55]]]]),
        'openapi.tuyaeu.com/v1.0/token*' => Http::response(['success' => true, 'result' => ['access_token' => 'tuya-tok']]),
        'openapi.tuyaeu.com/v1.0/devices/ty-1/status' => Http::response(['success' => true, 'result' => [['code' => 'va_temperature', 'value' => 236], ['code' => 'va_humidity', 'value' => 62]]]),
    ]);
    $clouds = app(Clouds::class);
    $sp = $clouds->save(actorFor($this->owner), new IotConnection, ['provider' => 'sensorpush', 'credentials' => ['email' => 'shop@example.com', 'password' => 'secret']]);
    $ty = $clouds->save(actorFor($this->owner), new IotConnection, ['provider' => 'tuya', 'region' => 'eu', 'credentials' => ['client_id' => 'cid', 'secret' => 'sec']]);
    $f = fridge($this, ['connection' => 'sensorpush', 'connection_id' => $sp->id, 'external_id' => 'sp-1']);
    $room = $this->sensors->save(actorFor($this->owner), new IotSensor, ['name' => 'Medicine store', 'kind' => 'room', 'branch_id' => $this->hq->id,
        'connection' => 'tuya', 'connection_id' => $ty->id, 'external_id' => 'ty-1']);

    expect($f->token)->toBeNull()->and($clouds->pollDue($this->tenant->id))->toBe(2)
        ->and($f->fresh())->last_temp->toEqual('5.0')->last_humidity->toBe(55) // 41 °F
        ->and($room->fresh())->last_temp->toEqual('23.6')->last_humidity->toBe(62)
        ->and($sp->fresh()->last_error)->toBeNull()
        ->and(fn () => $clouds->save(actorFor($this->owner), new IotConnection, ['provider' => 'tuya', 'credentials' => ['client_id' => 'x', 'secret' => 'y']]))->toThrow(DomainException::class, 'data centre');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'tuyaeu.com/v1.0/devices') && $r->hasHeader('access_token', 'tuya-tok') && strlen($r->header('sign')[0]) === 64);
});

it('keeps signed checks and the monthly temperature log for inspections', function () {
    $s = fridge($this);
    send($this, $s, ['temperature' => 4.2]);
    $this->sensors->check(actorFor($this->pharmacist), $s, 4.0, true);
    expect(fn () => $this->sensors->check(actorFor($this->pharmacist), $s, 9.0, false))->toThrow(DomainException::class, 'what is wrong');
    $owner = withMfa($this->owner);

    $this->actingAs($owner, 'web')->get(TemperatureLog::getUrl().'?sensor='.$s->id)->assertOk()->assertSee('temperature log')->assertSee('Abena')->assertSee('4.2 °C');
    $this->actingAs($owner, 'web')->get(IotSensorResource::getUrl())->assertOk()->assertSee('Vaccine fridge')->assertSee('4.2 °C');
    $this->actingAs($owner, 'web')->get(IotSensorResource::getUrl('edit', ['record' => $s]))->assertOk()->assertSee('Last 24 hours')->assertSee('Record a check');
    $this->actingAs($owner, 'web')->get(IotAlarms::getUrl())->assertOk();
    $this->actingAs($owner, 'web')->get(IotClouds::getUrl())->assertOk();
    Livewire::actingAs($owner)->test(IotPanel::class)->assertSee('Fridges checked today')->assertSee('1 of 1');
});

it('keeps readings once and from the future at now', function () {
    $s = fridge($this);
    send($this, $s, ['temperature' => 4.0, 'time' => now()->subMinutes(5)->getTimestamp()]);
    send($this, $s, ['temperature' => 4.0, 'time' => now()->subMinutes(5)->getTimestamp()]);
    send($this, $s, ['temperature' => 4.1, 'time' => now()->addDay()->toIso8601String()]);
    expect(IotReading::where('sensor_id', $s->id)->count())->toBe(2)->and($s->fresh()->last_reading_at->equalTo(now()->startOfSecond()))->toBeTrue();
});
