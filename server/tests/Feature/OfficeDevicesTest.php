<?php

use App\Domain\Licensing\LicenseManager;
use App\Models\Computer;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Till;
use Illuminate\Support\Str;

// Devices and computers in the new back office (settings and admin, part 6), as the current screens.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->hq = $this->b['HQ'];
    withMfa($this->owner);
    $this->till = Till::where('branch_id', $this->hq->id)->first();
});

it('registers a device with a code shown once, issues a new code, revokes it, and archives it', function () {
    $this->actingAs($this->owner, 'web');
    $o = $this->getJson("/office-api/devices/options?branch={$this->hq->id}")->assertOk()->json();
    expect(collect($o['tills'])->pluck('id')->all())->toContain($this->till->id)->and(collect($o['kinds'])->pluck('id')->all())->toContain('pos');

    $this->postJson('/office-api/devices', ['branch_id' => $this->hq->id, 'name' => 'Front PC', 'kind' => 'kds'])->assertStatus(422); // no restaurant here
    $r = $this->postJson('/office-api/devices', ['branch_id' => $this->hq->id, 'till_id' => $this->till->id, 'name' => 'Front PC', 'kind' => 'pos'])->assertOk()->json();
    expect($r['code'])->not->toBeEmpty()->and($r['expires'])->not->toBeNull();
    $dev = Device::where('name', 'Front PC')->sole();

    $l = $this->getJson('/office-api/devices?q=Front')->assertOk();
    expect($l->getContent())->not->toContain($r['code']) // the code is not shown again
        ->and($l->json('rows.0'))->toMatchArray(['name' => 'Front PC', 'status' => 'pending', 'can_reissue' => true, 'can_revoke' => true, 'can_fix' => false]);
    $again = $this->postJson("/office-api/devices/{$dev->id}/new-code")->assertOk()->json('code');
    expect($again)->not->toBe($r['code']);

    $this->postJson("/office-api/devices/{$dev->id}/revoke", ['reason' => ''])->assertStatus(422);
    $this->postJson("/office-api/devices/{$dev->id}/revoke", ['reason' => 'Replaced'])->assertOk();
    expect($dev->fresh()->status)->toBe('revoked');
    $this->postJson("/office-api/devices/{$dev->id}/archive", ['reason' => 'Thrown away'])->assertOk();
    expect($this->getJson('/office-api/devices?q=Front')->json('total'))->toBe(0)->and($this->getJson('/office-api/devices?q=Front&show=archived')->json('total'))->toBe(1);
});

it('sends a fix only with the Remote access add-on, names a computer, and keeps others out', function () {
    $this->actingAs($this->owner, 'web');
    $this->postJson('/office-api/devices', ['branch_id' => $this->hq->id, 'till_id' => $this->till->id, 'name' => 'Till 1', 'kind' => 'pos'])->assertOk();
    $dev = Device::where('name', 'Till 1')->sole();
    $dev->forceFill(['status' => 'active'])->save();
    $clerk = staff($this->owner, 'efua', 2);
    $it = withMfa(staff($this->owner, 'it', 4, 'it_admin'));

    $this->actingAs($it, 'web');
    $this->postJson("/office-api/devices/{$dev->id}/fix", ['command' => 'sync_now'])->assertForbidden(); // no add-on
    activateTestLicense(['features' => ['finance', 'hr', 'remote_access']]);
    LicenseManager::forget();
    expect(collect($this->getJson('/office-api/devices')->json('rows'))->firstWhere('id', $dev->id)['can_fix'])->toBeTrue();
    $this->postJson("/office-api/devices/{$dev->id}/fix", ['command' => 'wipe_disk'])->assertStatus(422);
    $this->postJson("/office-api/devices/{$dev->id}/fix", ['command' => 'collect_logs', 'reason' => 'Slow'])->assertOk();
    expect(DeviceCommand::where('device_id', $dev->id)->sole()->command)->toBe('collect_logs')
        ->and($this->getJson("/office-api/devices/{$dev->id}/fixes")->json('rows.0.what'))->toBe(DeviceCommand::COMMANDS['collect_logs']);

    $this->actingAs($this->owner, 'web');
    $c = new Computer;
    $c->forceFill(['computer_key' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'last_seen_at' => now(), 'first_seen_at' => now()])->save();
    $this->postJson("/office-api/computers/{$c->id}/name", ['name' => 'Front desk PC'])->assertOk();
    expect($c->fresh()->name)->toBe('Front desk PC')->and(collect($this->getJson('/office-api/computers')->assertOk()->json('rows'))->pluck('label')->all())->toContain('Front desk PC');

    $this->actingAs($clerk, 'web')->getJson('/office-api/devices')->assertForbidden();
    $this->getJson('/office-api/computers')->assertForbidden();
});
