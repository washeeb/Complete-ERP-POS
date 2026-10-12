<?php

use App\Domain\Access\AccessService;
use App\Domain\Devices\DeviceService;
use App\Domain\Support\DomainException;
use App\Models\AuditEvent;
use App\Models\Till;
use App\Security\ScopeType;
use App\Support\Settings;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->till = Till::where('branch_id', $this->branches['BA']->id)->orderBy('code')->first();
    $this->devices = app(DeviceService::class);
    [$this->device, $this->code] = $this->devices->register(actorFor($this->owner), [
        'branch_id' => $this->branches['BA']->id, 'till_id' => $this->till->id, 'name' => 'Till 1 PC', 'kind' => 'pos',
    ]);
    $this->cashier = staff($this->owner, 'esi', 2, 'teller', ScopeType::Till, $this->till->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $this->cashier, '4826');
});

function enroll($test): string
{
    return $test->postJson('/api/v1/devices/enroll', [
        'device_id' => $test->device->id, 'code' => $test->code, 'public_key' => str_repeat('k', 64),
    ])->assertCreated()->json('token');
}

it('enrols a registered device once, with its one-time code', function () {
    $token = enroll($this);

    expect($token)->not->toBeEmpty()->and($this->device->fresh()->status)->toBe('active');
    $this->postJson('/api/v1/devices/enroll', ['device_id' => $this->device->id, 'code' => $this->code, 'public_key' => str_repeat('k', 64)])
        ->assertUnauthorized()->assertJson(['error' => 'enrollment_failed']);
});

it('rejects a wrong or expired enrolment code', function () {
    $this->postJson('/api/v1/devices/enroll', ['device_id' => $this->device->id, 'code' => 'AAAA-BBBB-CCCC', 'public_key' => str_repeat('k', 64)])
        ->assertUnauthorized();
    $this->travel(25)->hours();
    $this->postJson('/api/v1/devices/enroll', ['device_id' => $this->device->id, 'code' => $this->code, 'public_key' => str_repeat('k', 64)])
        ->assertUnauthorized();
});

it('signs a cashier in by PIN on an enrolled till and identifies them', function () {
    $deviceToken = enroll($this);

    $login = $this->withToken($deviceToken)->postJson('/api/v1/pos/login', ['username' => 'esi', 'pin' => '4826'])
        ->assertOk()->json();
    app('auth')->forgetGuards();

    $this->withToken($login['token'])->getJson('/api/v1/pos/me')
        ->assertOk()->assertJsonPath('user.username', 'esi')->assertJsonPath('device.id', $this->device->id);
    expect(AuditEvent::where('action', 'pos.login')->where('device_id', $this->device->id)->exists())->toBeTrue();
});

it('refuses a person with no till access at that branch', function () {
    $deviceToken = enroll($this);
    $hqCashier = staff($this->owner, 'hqc', 2, 'teller', ScopeType::Branch, $this->branches['HQ']->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $hqCashier, '7391');

    $this->withToken($deviceToken)->postJson('/api/v1/pos/login', ['username' => 'hqc', 'pin' => '7391'])
        ->assertForbidden()->assertJson(['error' => 'scope_denied']);
});

it('locks the PIN after repeated wrong attempts', function () {
    $deviceToken = enroll($this);
    $max = app(Settings::class)->get('security.pin_max_attempts', $this->tenant->id);

    for ($i = 0; $i < $max; $i++) {
        $this->withToken($deviceToken)->postJson('/api/v1/pos/login', ['username' => 'esi', 'pin' => '0001'])->assertUnauthorized();
    }
    $this->withToken($deviceToken)->postJson('/api/v1/pos/login', ['username' => 'esi', 'pin' => '4826'])
        ->assertStatus(423)->assertJson(['error' => 'pin_locked']);
    expect(AuditEvent::where('action', 'pos.pin_locked')->exists())->toBeTrue();
});

it('revoking a device ends the device and every session on it', function () {
    $deviceToken = enroll($this);
    $userToken = $this->withToken($deviceToken)->postJson('/api/v1/pos/login', ['username' => 'esi', 'pin' => '4826'])->json('token');

    $this->devices->revoke(actorFor($this->owner), $this->device->fresh(), 'Stolen');
    app('auth')->forgetGuards();

    $this->withToken($userToken)->getJson('/api/v1/pos/me')->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withToken($deviceToken)->postJson('/api/v1/pos/login', ['username' => 'esi', 'pin' => '4826'])->assertUnauthorized();
});

it('does not accept a device token as a staff session or vice versa', function () {
    $deviceToken = enroll($this);
    $this->withToken($deviceToken)->getJson('/api/v1/pos/me')->assertUnauthorized();
});

it('rejects trivially guessable PINs', function () {
    app(AccessService::class)->setPin(actorFor($this->owner), $this->cashier, '1234');
})->throws(DomainException::class, 'too easy');
