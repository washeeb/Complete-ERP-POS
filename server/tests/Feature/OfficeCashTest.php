<?php

use App\Domain\Pos\CashControl;
use App\Models\Account;
use App\Models\CashHandover;
use App\Models\CashVariance;
use App\Models\DayClose;
use App\Models\ShiftTemplate;
use App\Models\Till;
use App\Models\Vault;
use App\Models\VaultCover;
use App\Models\VaultSession;
use App\Security\ScopeType;
use App\Support\Settings;

// The Cash area in the new back office (part 14): Cash desk, Income control, Close the day, Shifts and vaults.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = $this->branches['HQ'];
    $this->till = Till::where('branch_id', $this->hq->id)->first();
    withMfa($this->owner);
});

it('runs the cash desk and income control: float, blind counts with a recount, handover, decision and deposit', function () {
    app(Settings::class)->set(actorFor($this->owner), 'cash.vaults', true);
    app(CashControl::class)->ensureVaults($this->tenant->id);
    $vault = Vault::where('till_id', $this->till->id)->whereHas('shiftTemplate', fn ($q) => $q->where('name', 'Morning'))->firstOrFail();
    $teller = staff($this->owner, 'esi', 2, 'teller', ScopeType::Till, $this->till->id);
    $cashier = withMfa(staff($this->owner, 'kofi', 3, 'cashier', ScopeType::Branch, $this->hq->id));
    $controller = withMfa(staff($this->owner, 'ama', 4, 'income_controller'));
    $elsewhere = withMfa(staff($this->owner, 'yaw', 3, 'cashier', ScopeType::Branch, $this->branches['BA']->id));

    // The cashier sees only their branch's vaults and may not open Income control.
    $this->actingAs($cashier, 'web');
    $desk = $this->getJson('/office-api/cash/desk')->assertOk()->json();
    expect(collect($desk['branches'])->pluck('id')->all())->toBe([$this->hq->id])->and(collect($desk['rows'])->pluck('vault_id'))->toContain($vault->id)
        ->and($desk['denominations'])->not->toBeEmpty()->and($desk['can'])->toMatchArray(['issue' => true, 'receive' => true, 'hand_over' => true]);
    expect($this->getJson('/office-api/cash/income')->assertOk()->json('can'))->toMatchArray(['receive' => false, 'decide' => false, 'deposit' => false]);
    expect(collect($this->getJson("/office-api/cash/desk/tellers?vault={$vault->id}")->json('tellers'))->pluck('id')->all())->toContain($teller->id)->not->toContain($cashier->id);

    // Issue the float, never to oneself.
    $this->postJson('/office-api/cash/desk/issue', ['vault' => $vault->id, 'teller' => $cashier->id, 'float' => '200'])->assertStatus(422);
    $this->postJson('/office-api/cash/desk/issue', ['vault' => $vault->id, 'teller' => $teller->id, 'float' => '200'])->assertOk();
    $s = VaultSession::sole();
    expect($s->status)->toBe('issued')->and($s->float_minor)->toBe(20000);

    // A cashier of another branch cannot touch it.
    $this->actingAs($elsewhere, 'web');
    $this->postJson("/office-api/cash/desk/{$s->id}/cancel", ['reason' => 'x'])->assertNotFound();

    // The teller has sold and counted GHS 290.00 of an expected GHS 300.00 (the till's part, done by the till).
    $s->forceFill(['status' => 'counted', 'expected_minor' => 30000, 'teller_count_minor' => 29000, 'counted_at' => now()])->save();

    // The cashier counts blind: a different first count asks for a recount; the second stands.
    $this->actingAs($cashier, 'web');
    $this->postJson("/office-api/cash/desk/{$s->id}/receive", ['denoms' => ['n200' => 1, 'n50' => 1, 'n20' => 2, 'n5' => 1]])->assertOk()->assertJsonPath('status', 'recount');
    expect($this->getJson('/office-api/cash/desk')->json('rows.0.session.recount_asked'))->toBeTrue();
    $this->postJson("/office-api/cash/desk/{$s->id}/receive", ['denoms' => ['n200' => 1, 'n50' => 1, 'n20' => 2]])->assertOk()->assertJsonPath('status', 'received');
    expect(CashVariance::sole()->amount_minor)->toBe(-1000);

    // Hand over the received tray.
    $mine = $this->getJson('/office-api/cash/desk')->json('mine');
    expect(collect($mine)->pluck('id')->all())->toBe([$s->id]);
    $this->postJson('/office-api/cash/desk/hand-over', ['branch' => $this->hq->id, 'sessions' => [$s->id]])->assertOk();
    $h = CashHandover::sole();
    expect($h->declared_minor)->toBe(29000);

    // The income controller counts the bundle blind; the "count again" is remembered by the server, not the page.
    $this->actingAs($controller, 'web');
    $inc = $this->getJson('/office-api/cash/income')->assertOk()->json();
    expect(collect($inc['waiting'])->pluck('id')->all())->toBe([$h->id])->and($inc['differences'])->toHaveCount(1);
    $this->postJson("/office-api/cash/income/{$h->id}/receive", ['denoms' => ['n200' => 1, 'n50' => 1]])->assertOk()->assertJsonPath('status', 'recount');
    expect($this->getJson('/office-api/cash/income')->json('waiting.0.recount'))->toBeTrue();
    $this->postJson("/office-api/cash/income/{$h->id}/receive", ['denoms' => ['n200' => 1, 'n50' => 1, 'n20' => 2]])->assertOk()->assertJsonPath('status', 'received');
    $inc = $this->getJson('/office-api/cash/income')->json();
    expect($inc['waiting'])->toBe([])->and($inc['received'][0])->toMatchArray(['number' => $h->number, 'declared' => 29000, 'counted' => 29000, 'deposited' => false])
        ->and($inc['received'][0]['sessions'][0])->toMatchArray(['expected' => 30000, 'teller_count' => 29000, 'cashier_count' => 29000]);

    // Decide the teller's shortage, then record the deposit.
    $v = CashVariance::sole();
    $this->postJson("/office-api/cash/income/differences/{$v->id}", ['treatment' => 'over_short', 'reason' => 'Miscount'])->assertOk();
    expect($v->fresh()->status)->not->toBe('open');
    $bank = Account::where('system_key', 'bank')->firstOrFail();
    $this->postJson("/office-api/cash/income/{$h->id}/deposit", ['bank' => $bank->id, 'amount' => '290', 'date' => now()->toDateString(), 'slip' => ''])->assertStatus(422);
    $this->postJson("/office-api/cash/income/{$h->id}/deposit", ['bank' => $bank->id, 'amount' => '290', 'date' => now()->toDateString(), 'slip' => 'DS-1'])->assertOk();
    expect($h->fresh()->deposit_transfer_id)->not->toBeNull()->and($this->getJson('/office-api/cash/income')->json('received.0.deposited'))->toBeTrue();
});

it('closes the day for a branch the person may close, and lists the recent closes', function () {
    $this->actingAs($this->owner, 'web');
    $d = $this->getJson("/office-api/cash/close-day?branch={$this->hq->id}")->assertOk()->json();
    expect($d['branch'])->toBe($this->hq->id)->and($d['date'])->toBe($d['today'])->and($d['closed'])->toBeNull()->and($d['deposits_here'])->toBeTrue()
        ->and(collect($d['checks'])->pluck('key'))->toContain('tills');

    // Cash taken to the bank needs its slip; what is kept in the safe is recorded as given.
    // (no backup has run in a test, so the day is closed with a problem and needs a note)
    $this->postJson('/office-api/cash/close-day', ['branch' => $this->hq->id, 'date' => $d['today'], 'safe' => '1'])->assertStatus(422)
        ->assertJsonPath('detail', fn ($m) => str_contains($m, 'write why the day is closed anyway'));
    $this->postJson('/office-api/cash/close-day', ['branch' => $this->hq->id, 'date' => $d['today'], 'banked' => '50', 'note' => 'No backup yet'])->assertStatus(422)
        ->assertJsonPath('detail', fn ($m) => str_contains($m, 'deposit slip'));
    $this->postJson('/office-api/cash/close-day', ['branch' => $this->hq->id, 'date' => $d['today'], 'safe' => '12.50', 'note' => 'Quiet day'])->assertOk();
    $c = DayClose::sole();
    expect($c->safe_minor)->toBe(1250)->and($c->closed_by_name)->toBe($this->owner->name);

    $d = $this->getJson("/office-api/cash/close-day?branch={$this->hq->id}")->json();
    expect($d['closed'])->toMatchArray(['by' => $this->owner->name, 'note' => 'Quiet day'])->and($d['form']['safe'])->toBe('12.50')
        ->and($d['recent'][0])->toMatchArray(['date' => $d['today'], 'safe' => 1250]);

    // A branch manager closes only their own branch.
    $mgr = withMfa(staff($this->owner, 'efua', 4, 'branch_manager', ScopeType::Branch, $this->branches['BA']->id));
    $this->actingAs($mgr, 'web');
    expect(collect($this->getJson('/office-api/cash/close-day')->json('branches'))->pluck('id')->all())->toBe([$this->branches['BA']->id]);
    $this->postJson('/office-api/cash/close-day', ['branch' => $this->hq->id, 'date' => $d['today']])->assertStatus(422);
});

it('sets up shifts, vaults and each teller\'s own vault, within the branches the person sets up', function () {
    $this->actingAs($this->owner, 'web');
    $set = $this->getJson('/office-api/cash/setup')->assertOk()->json();
    expect($set['personal'])->toBeFalse()->and($set['enabled'])->toBeFalse()->and($set['templates'])->not->toBeEmpty();

    // A new shift gives each till its vault.
    $this->postJson('/office-api/cash/setup/shifts', ['name' => 'Late', 'starts_at' => '22:30', 'ends_at' => '23:30', 'sort' => 9, 'is_active' => true])->assertOk();
    $night = ShiftTemplate::where('name', 'Late')->sole();
    $v = Vault::where('till_id', $this->till->id)->where('shift_template_id', $night->id)->sole();
    $this->postJson("/office-api/cash/setup/vaults/{$v->id}", ['float' => '150', 'is_active' => true])->assertOk();
    expect($v->fresh()->standard_float_minor)->toBe(15000);

    // A branch manager sets up their own branch only.
    $mgr = withMfa(staff($this->owner, 'efua', 4, 'branch_manager', ScopeType::Branch, $this->branches['BA']->id));
    $this->actingAs($mgr, 'web');
    expect(collect($this->getJson('/office-api/cash/setup')->json('vaults'))->pluck('branch')->unique()->values()->all())->toBe(['BA']);
    $this->postJson("/office-api/cash/setup/vaults/{$v->id}", ['float' => '1', 'is_active' => true])->assertNotFound();

    // Each teller's own vault: add one, then lend it for today.
    $this->actingAs($this->owner, 'web');
    app(Settings::class)->set(actorFor($this->owner), 'cash.personal_vaults', 'manager');
    $ama = staff($this->owner, 'ama', 2, 'teller', ScopeType::Till, $this->till->id);
    $kojo = staff($this->owner, 'kojo', 2, 'teller', ScopeType::Till, $this->till->id);
    $who = $this->getJson("/office-api/cash/setup/sellers?branch={$this->hq->id}")->assertOk()->json();
    expect(collect($who['people'])->pluck('id'))->toContain($ama->id)->and(collect($who['tills'])->pluck('id'))->toContain($this->till->id);
    $this->postJson('/office-api/cash/setup/own-vaults', ['name' => 'Vault 1', 'branch' => $this->hq->id, 'holder' => $ama->id, 'till' => $this->till->id, 'float' => '100', 'is_active' => true])->assertOk();
    $own = Vault::whereNull('shift_template_id')->sole();
    expect($own->holder_id)->toBe($ama->id)->and($this->getJson('/office-api/cash/setup')->json('own_vaults.0'))->toMatchArray(['name' => 'Vault 1', 'holder' => 'Ama']);
    $this->postJson("/office-api/cash/setup/vaults/{$own->id}/lend", ['user' => $kojo->id, 'reason' => 'Ama is on leave'])->assertOk();
    expect(VaultCover::sole()->user_id)->toBe($kojo->id)->and($this->getJson('/office-api/cash/setup')->json('own_vaults.0.lent.to'))->toBe('Kojo');
});
