<?php

use App\Models\IotAlarm;
use App\Models\IotCheck;
use App\Models\IotConnection;
use App\Models\IotSensor;
use App\Security\ScopeType;
use Illuminate\Support\Facades\Http;

// Sensors in the new back office (part 32): sensors, alarms, the temperature log and sensor clouds.

beforeEach(function () {
    $this->travelTo(now()->setTime(12, 0));
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    activateTestLicense(['features' => ['finance', 'hr', 'iot', 'service']]);
    $this->hq = $this->b['HQ'];
    withMfa($this->owner);
    $this->pharmacist = staff($this->owner, 'abena', 4, 'pharmacist', ScopeType::Branch, $this->hq->id);
});

function officeReading($t, IotSensor $s, array $payload)
{
    return $t->postJson(route('iot.reading', ['token' => $s->token]), $payload);
}

it('adds a sensor, shows its reading and address, signs a check, and archives it', function () {
    $this->actingAs($this->owner, 'web');
    $form = $this->getJson('/office-api/sensors/form')->assertOk()->json();
    expect(collect($form['kinds'])->firstWhere('id', 'freezer'))->toMatchArray(['min_temp' => '-25', 'max_temp' => '-15'])->and($form['values']['branch_id'])->not->toBe('');
    $this->postJson('/office-api/sensors', ['name' => 'Cold room', 'kind' => 'fridge', 'branch_id' => $this->hq->id, 'min_temp' => '8', 'max_temp' => '2', 'connection' => 'direct'])
        ->assertStatus(422); // lowest above highest
    $id = $this->postJson('/office-api/sensors', ['name' => 'Vaccine fridge', 'kind' => 'fridge', 'branch_id' => $this->hq->id, 'min_temp' => '2', 'max_temp' => '8',
        'delay_minutes' => 10, 'silent_minutes' => 30, 'connection' => 'direct'])->assertOk()->json('id');
    $s = IotSensor::find($id);
    $address = $this->getJson("/office-api/sensors/{$id}/address")->assertOk()->json('url');
    expect($address)->toBe(route('iot.reading', ['token' => $s->token]));

    officeReading($this, $s, ['temperature' => 4.6, 'humidity' => 50, 'battery' => 88])->assertOk();
    $list = $this->getJson('/office-api/sensors')->assertOk()->json();
    expect($list['total'])->toBe(1)->and($list['rows'][0])->toMatchArray(['name' => 'Vaccine fridge', 'reading' => '4.6 °C · 50 %', 'status' => 'ok', 'battery' => 88]);
    $one = $this->getJson("/office-api/sensors/{$id}")->assertOk()->json();
    expect($one)->toMatchArray(['reading' => '4.6 °C · 50 %', 'temperature' => true, 'min' => 2.0, 'max' => 8.0])->and($one['chart'])->toHaveCount(1)
        ->and($one['actions'])->toMatchArray(['check' => true, 'address' => true, 'new_address' => true]);

    $this->postJson("/office-api/sensors/{$id}/action", ['action' => 'check', 'seen' => '4.5', 'ok' => true, 'note' => 'Morning'])->assertOk();
    expect(IotCheck::where('sensor_id', $id)->sole()->user_name)->toBe('Ama Owner')
        ->and($this->getJson("/office-api/sensors/{$id}")->json('checks.0'))->toMatchArray(['seen' => '4.5 °C', 'ok' => true, 'note' => 'Morning']);
    $old = $s->token;
    $this->postJson("/office-api/sensors/{$id}/action", ['action' => 'new_address'])->assertOk();
    expect($s->fresh()->token)->not->toBe($old);

    $this->postJson("/office-api/sensors/{$id}", ['name' => 'Vaccine fridge 1', 'kind' => 'fridge', 'branch_id' => $this->hq->id, 'min_temp' => '2', 'max_temp' => '8',
        'connection' => 'direct'])->assertOk();
    expect($s->fresh()->name)->toBe('Vaccine fridge 1');
    $this->postJson("/office-api/sensors/{$id}/archive", ['reason' => 'Fridge sold'])->assertOk();
    expect($this->getJson('/office-api/sensors')->json('total'))->toBe(0)->and($this->getJson('/office-api/sensors?show=archived')->json('total'))->toBe(1);

    // The pharmacist sees and signs, but does not see the address or change sensors.
    $this->postJson("/office-api/sensors/{$id}/archive", ['restore' => true])->assertOk();
    $this->actingAs($this->pharmacist, 'web');
    $this->getJson("/office-api/sensors/{$id}")->assertOk();
    $this->getJson("/office-api/sensors/{$id}/address")->assertForbidden();
    $this->postJson("/office-api/sensors/{$id}", ['name' => 'X', 'kind' => 'fridge', 'branch_id' => $this->hq->id, 'connection' => 'direct'])->assertForbidden();
});

it('lists alarms and acknowledges one with what was done', function () {
    $this->actingAs($this->owner, 'web');
    $id = $this->postJson('/office-api/sensors', ['name' => 'Vaccine fridge', 'kind' => 'fridge', 'branch_id' => $this->hq->id, 'min_temp' => '2', 'max_temp' => '8',
        'delay_minutes' => 10, 'connection' => 'direct'])->assertOk()->json('id');
    $s = IotSensor::find($id);
    officeReading($this, $s, ['temperature' => 9.5])->assertOk();
    $this->travel(11)->minutes();
    officeReading($this, $s, ['temperature' => 10.4])->assertOk();
    $alarm = IotAlarm::sole();

    $list = $this->getJson('/office-api/sensors/alarms')->assertOk()->json();
    expect($list['total'])->toBe(1)->and($list['rows'][0])->toMatchArray(['sensor' => 'Vaccine fridge', 'kind' => 'Too warm', 'worst' => '10.4 °C', 'status' => 'open', 'can_acknowledge' => true]);
    expect($this->getJson("/office-api/sensors/{$id}")->json('alarms.0.id'))->toBe($alarm->id);
    $this->postJson("/office-api/sensors/alarms/{$alarm->id}", ['action' => 'nope'])->assertStatus(422);
    $this->postJson("/office-api/sensors/alarms/{$alarm->id}", ['action' => 'moved', 'notes' => 'Insulin moved to fridge 2'])->assertOk();
    expect($alarm->fresh()->status)->toBe('acknowledged');
    $this->travel(5)->minutes();
    officeReading($this, $s, ['temperature' => 5.0])->assertOk();
    expect($alarm->fresh()->status)->toBe('closed');
    expect($this->getJson('/office-api/sensors/alarms')->json('total'))->toBe(0)->and($this->getJson('/office-api/sensors/alarms?open=0')->json('total'))->toBe(1);
});

it('shows the month\'s temperature log, and keeps sensor clouds without sending their secrets back', function () {
    $this->actingAs($this->owner, 'web');
    $id = $this->postJson('/office-api/sensors', ['name' => 'Vaccine fridge', 'kind' => 'fridge', 'branch_id' => $this->hq->id, 'min_temp' => '2', 'max_temp' => '8',
        'connection' => 'direct'])->assertOk()->json('id');
    officeReading($this, IotSensor::find($id), ['temperature' => 4.0])->assertOk();
    $this->postJson("/office-api/sensors/{$id}/action", ['action' => 'check', 'seen' => '4', 'ok' => true])->assertOk();
    $log = $this->getJson('/office-api/sensors/log?month='.now()->format('Y-m'))->assertOk()->json();
    expect($log['sensor'])->toBe($id)->and($log['log']['name'])->toBe('Vaccine fridge')->and($log['log']['alarm_count'])->toBe(0)
        ->and(collect($log['log']['days'])->last()['checks'][0])->toContain('4 °C · Ama Owner');

    Http::fake(['api.sensorpush.com/*' => Http::response(['message' => 'bad login'], 401)]);
    $this->postJson('/office-api/sensors/clouds', ['provider' => 'sensorpush', 'email' => 'shop@example.com'])->assertStatus(422); // no password
    $cid = $this->postJson('/office-api/sensors/clouds', ['provider' => 'sensorpush', 'name' => 'Shop', 'email' => 'shop@example.com', 'password' => 'secret-pass'])->assertOk()->json('id');
    $rows = $this->getJson('/office-api/sensors/clouds')->assertOk()->json('rows');
    expect($rows[0]['values'])->toMatchArray(['email' => 'shop@example.com', 'password' => '', 'secret' => ''])
        ->and(json_encode($rows))->not->toContain('secret-pass');
    // Changing it with the password left empty keeps the saved one.
    $this->postJson("/office-api/sensors/clouds/{$cid}", ['provider' => 'sensorpush', 'name' => 'Shop fridges', 'email' => 'shop@example.com', 'password' => ''])->assertOk();
    expect(IotConnection::find($cid)->only(['name']))->toBe(['name' => 'Shop fridges'])->and(IotConnection::find($cid)->credentials['password'])->toBe('secret-pass');
    $this->postJson('/office-api/sensors', ['name' => 'Cold room', 'kind' => 'cold_room', 'branch_id' => $this->hq->id, 'connection' => 'sensorpush',
        'connection_id' => $cid, 'external_id' => 'sp-1'])->assertOk();
    $this->postJson("/office-api/sensors/clouds/{$cid}/read")->assertStatus(422)->assertJsonPath('error', 'cloud');

    $this->actingAs($this->pharmacist, 'web');
    $this->getJson('/office-api/sensors/clouds')->assertForbidden();
});
