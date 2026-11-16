<?php

use App\Domain\Access\AccessService;
use App\Domain\Devices\DeviceService;
use App\Domain\Pos\TellerVaults;
use App\Models\Shift;
use App\Models\Till;
use App\Models\Vault;
use App\Security\ScopeType;
use App\Support\Settings;
use Illuminate\Support\Carbon;

// The manager's vault count in the new back office (release 2 completed): blind, note by note; a count that differs from
// the teller's is counted again once, and the second count stands, as on the classic screen.

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-30 10:00', 'Africa/Accra'));
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $till = Till::where('branch_id', $this->branches['HQ']->id)->first();
    $a = actorFor($this->owner);
    app(Settings::class)->set($a, 'cash.personal_vaults', 'manager');
    [, $code] = app(DeviceService::class)->register($a, ['branch_id' => $this->branches['HQ']->id, 'till_id' => $till->id, 'name' => 'Till PC', 'kind' => 'pos']);
    $deviceToken = $this->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->assertCreated()->json('token');
    $this->esi = staff($this->owner, 'esi', 2, 'teller', ScopeType::Till, $till->id);
    app(AccessService::class)->setPin($a, $this->esi, '4826');
    $this->kojo = withMfa(staff($this->owner, 'kojo', 4, 'branch_manager', ScopeType::Branch, $this->branches['HQ']->id));
    $vault = app(TellerVaults::class)->save($a, new Vault, ['name' => 'Vault 1', 'branch_id' => $this->branches['HQ']->id, 'holder_id' => $this->esi->id, 'standard_float_minor' => 20000]);
    // Esi works her shift on her vault and counts GH₵ 200 at the close.
    $token = $this->withToken($deviceToken)->postJson('/api/v1/pos/login', ['username' => 'esi', 'pin' => '4826'])->assertOk()->json('token');
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/v1/pos/shifts/open', ['opening_float_minor' => 0, 'vault_id' => $vault->id])->assertCreated();
    $this->withToken($token)->postJson('/api/v1/pos/shifts/close', ['counted_cash_minor' => 20000])->assertOk();
    app('auth')->forgetGuards();
    $this->shift = Shift::sole();
});

afterEach(fn () => Carbon::setTestNow());

it('counts a vault blind, note by note: a different count is counted again, the second stands', function () {
    $this->actingAs($this->kojo, 'web');
    $s = $this->getJson("/office-api/shifts/{$this->shift->id}")->assertOk()->json();
    expect($s['actions']['count_vault'])->toBeTrue()
        ->and(collect($s['denominations'])->pluck('key'))->toContain('n200')->toContain('c1p')
        ->and($s['vault_waiting'])->toBeTrue();

    $count = ['denoms' => ['n100' => 1, 'n50' => 1, 'n20' => 2]]; // GH₵ 190, the teller said 200
    $this->postJson("/office-api/shifts/{$this->shift->id}/count-vault", $count)->assertOk()->assertJsonPath('status', 'recount');
    $this->postJson("/office-api/shifts/{$this->shift->id}/count-vault", $count)->assertOk()->assertJsonPath('status', 'checked')
        ->assertJsonPath('shift.counted', 19000)->assertJsonPath('shift.vault_waiting', false)->assertJsonPath('shift.actions.count_vault', false);
    expect($this->shift->fresh()->checked_by_name)->toBe($this->kojo->name);
    $this->postJson("/office-api/shifts/{$this->shift->id}/count-vault", $count)->assertUnprocessable(); // counted already
});

it('lets nobody count their own vault, and refuses a note that does not exist', function () {
    $this->actingAs($this->kojo, 'web');
    $this->postJson("/office-api/shifts/{$this->shift->id}/count-vault", ['denoms' => ['n1000' => 1]])->assertUnprocessable();
    $this->flushSession();
    $this->actingAs($this->esi->fresh(), 'web');
    $this->postJson("/office-api/shifts/{$this->shift->id}/count-vault", ['denoms' => ['n200' => 1]])->assertForbidden(); // a teller counts no vault
    expect($this->shift->fresh()->checked_at)->toBeNull();
});
