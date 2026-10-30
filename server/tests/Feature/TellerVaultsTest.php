<?php

use App\Domain\Access\AccessService;
use App\Domain\Devices\DeviceService;
use App\Domain\Pos\TellerVaults;
use App\Domain\Support\DomainException;
use App\Models\Shift;
use App\Models\Till;
use App\Models\Vault;
use App\Security\ScopeType;
use App\Support\Settings;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-30 10:00', 'Africa/Accra'));
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->till = Till::where('branch_id', $this->branches['HQ']->id)->first();
    $this->a = actorFor($this->owner);
    app(Settings::class)->set($this->a, 'cash.personal_vaults', 'manager');

    [$device, $code] = app(DeviceService::class)->register($this->a, ['branch_id' => $this->branches['HQ']->id, 'till_id' => $this->till->id, 'name' => 'Till PC', 'kind' => 'pos']);
    $this->deviceToken = $this->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->assertCreated()->json('token');
    $this->esi = staff($this->owner, 'esi', 2, 'teller', ScopeType::Till, $this->till->id);
    app(AccessService::class)->setPin($this->a, $this->esi, '4826');
    $this->ama = staff($this->owner, 'ama', 2, 'teller', ScopeType::Till, $this->till->id);
    app(AccessService::class)->setPin($this->a, $this->ama, '5173');
    $this->kojo = staff($this->owner, 'kojo', 4, 'branch_manager', ScopeType::Branch, $this->branches['HQ']->id);
    app(AccessService::class)->setPin($this->a, $this->kojo, '7391');

    $vaults = app(TellerVaults::class);
    $this->v1 = $vaults->save($this->a, new Vault, ['name' => 'Vault 1', 'branch_id' => $this->branches['HQ']->id, 'holder_id' => $this->esi->id, 'standard_float_minor' => 20000]);
    $this->v2 = $vaults->save($this->a, new Vault, ['name' => 'Vault 2', 'branch_id' => $this->branches['HQ']->id, 'holder_id' => $this->ama->id, 'standard_float_minor' => 20000]);

    $this->signIn = function (string $username, string $pin) {
        app('auth')->forgetGuards();
        $token = $this->withToken($this->deviceToken)->postJson('/api/v1/pos/login', ['username' => $username, 'pin' => $pin])->assertOk()->json('token');
        app('auth')->forgetGuards();

        return $token;
    };
});

afterEach(fn () => Carbon::setTestNow());

it('opens a till only on the teller\'s own vault, and a manager recounts it blind before its over/short is booked', function () {
    $esi = ($this->signIn)('esi', '4826');
    $open = fn (array $extra = []) => $this->withToken($esi)->postJson('/api/v1/pos/shifts/open', ['opening_float_minor' => 0] + $extra);

    // The till offers only her own vault.
    expect(collect($this->withToken($esi)->getJson('/api/v1/pos/bootstrap')->assertOk()->json('personal_vaults.mine'))->pluck('name')->all())->toBe(['Vault 1']);
    $open()->assertStatus(422)->assertJsonPath('error', 'no_vault');
    $open(['vault_id' => $this->v2->id])->assertStatus(422)->assertJsonPath('error', 'not_your_vault');
    $open(['vault_id' => $this->v1->id])->assertCreated()->assertJsonPath('shift.vault', 'Vault 1')->assertJsonPath('shift.opening_float_minor', 20000);
    $this->withToken($esi)->postJson('/api/v1/pos/shifts/close', ['counted_cash_minor' => 20000])->assertOk();

    $shift = Shift::sole();
    expect($shift->vault_id)->toBe($this->v1->id)->and($shift->checked_at)->toBeNull();

    $vaults = app(TellerVaults::class);
    expect(fn () => $vaults->check(actorFor($this->esi), $shift, null, 20000))->toThrow(Exception::class);
    // The manager counts GHS 190: one blind recount, then the second count stands.
    expect($vaults->check(actorFor($this->kojo), $shift, null, 19000)['status'])->toBe('recount')
        ->and($vaults->check(actorFor($this->kojo), $shift->fresh(), null, 19000)['status'])->toBe('checked');
    expect($shift->fresh())->checked_count_minor->toBe(19000)->over_short_minor->toBe(-1000)->checked_by_name->toBe('Kojo');
    expect(fn () => $vaults->check(actorFor($this->kojo), $shift->fresh(), null, 19000))->toThrow(DomainException::class, 'counted already');
});

it('lets a manager lend a vault for the day by PIN at the till, and the holder cannot open it meanwhile', function () {
    // Ama has no vault of her own today: Vault 2 is switched off.
    app(TellerVaults::class)->save($this->a, $this->v2, ['name' => 'Vault 2', 'branch_id' => $this->branches['HQ']->id, 'holder_id' => $this->ama->id, 'is_active' => false]);
    $ama = ($this->signIn)('ama', '5173');
    expect($this->withToken($ama)->getJson('/api/v1/pos/bootstrap')->json('personal_vaults.mine'))->toBe([]);

    $lend = fn (array $approval) => $this->withToken($ama)->postJson('/api/v1/pos/vaults/lend', ['vault_id' => $this->v1->id, 'reason' => 'Esi is on leave', 'approval' => $approval]);
    $lend(['username' => 'kojo', 'pin' => '0000'])->assertStatus(422)->assertJsonPath('error', 'approval_failed');
    $lend(['username' => 'kojo', 'pin' => '7391'])->assertOk()->assertJsonPath('lent_by', 'Kojo');
    expect(collect($this->withToken($ama)->getJson('/api/v1/pos/bootstrap')->json('personal_vaults.mine'))->pluck('lent_by', 'name')->all())->toBe(['Vault 1' => 'Kojo']);
    $this->withToken($ama)->postJson('/api/v1/pos/shifts/open', ['opening_float_minor' => 0, 'vault_id' => $this->v1->id])->assertCreated();

    // Esi, the holder, no longer sees it today.
    expect(app(TellerVaults::class)->availableFor($this->esi, $this->till))->toHaveCount(0);
});

it('signs a teller in with her staff card and PIN, and a lost card stops working', function () {
    $code = app(AccessService::class)->issueBadge($this->a, $this->esi);
    expect($code)->toStartWith('SLB1-')->and($this->esi->fresh()->badge_hash)->toBe(hash('sha256', $code));

    $login = fn (string $u, string $p) => $this->withToken($this->deviceToken)->postJson('/api/v1/pos/login', ['username' => $u, 'pin' => $p]);
    $login($code, '4826')->assertOk()->assertJsonPath('user.username', 'esi');
    app('auth')->forgetGuards();
    $login(strtolower($code), '9999')->assertStatus(401)->assertJsonPath('error', 'invalid_credentials');
    app('auth')->forgetGuards();

    app(AccessService::class)->revokeBadge($this->a, $this->esi->fresh());
    $login($code, '4826')->assertStatus(401)->assertJsonPath('error', 'invalid_credentials');
});
