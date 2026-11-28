<?php

use App\Domain\Access\AccessService;
use App\Domain\Devices\DeviceService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Office\ShiftsScreen;
use App\Domain\Pos\CashControl;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\TaxCalculator;
use App\Models\Device;
use App\Models\PriceList;
use App\Models\Shift;
use App\Models\Till;
use App\Models\Vault;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Qty;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A till wiped, reinstalled or replaced while a shift was open (owner 2026-11-28): the till added in its place continues
 * the shift with a manager's PIN, or a manager closes it in the back office, also on a cash-control vault.
 */
beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->till = Till::where('branch_id', $this->branches['HQ']->id)->first();
    $this->bread = makeProduct($this->owner, 'BREAD', 'Sliced bread', retail: '50.00', tax: 'EXEMPT');
    app(InventoryService::class)->adjust(actorFor($this->owner), Warehouse::find($this->till->warehouse_id), $this->bread, $this->bread->units->first(), '20', 'opening', '');
    $this->teller = staff($this->owner, 'esi', 2, 'teller', ScopeType::Till, $this->till->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $this->teller, '4826');
    $this->manager = staff($this->owner, 'kofi', 4, 'branch_manager', ScopeType::Branch, $this->branches['HQ']->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $this->manager, '7391');
    $this->shifts = app(ShiftService::class);
});

/** Register and enrol a till computer on the HQ till; returns [device, device token]. */
function lostTillDevice($t, string $name): array
{
    [$device, $code] = app(DeviceService::class)->register(actorFor($t->owner), ['branch_id' => $t->branches['HQ']->id, 'till_id' => $t->till->id, 'name' => $name, 'kind' => 'pos']);
    $token = $t->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->assertCreated()->json('token');

    return [$device, $token];
}

/** The teller signs in on a till computer; returns the session token. */
function lostTillLogin($t, string $deviceToken): string
{
    app('auth')->forgetGuards();
    $token = $t->withToken($deviceToken)->postJson('/api/v1/pos/login', ['username' => 'esi', 'pin' => '4826'])->assertOk()->json('token');
    app('auth')->forgetGuards();

    return $token;
}

function lostTillSale($t, string $shiftId, int $seq): array
{
    $list = PriceList::where('code', 'RETAIL')->first();
    $unit = $t->bread->units->first();
    $price = app(PriceResolver::class)->resolve($list, $t->bread->id, $unit->id, Qty::toMilli('1'), now());
    $calc = TaxCalculator::line($price->amount_minor, [], $list->prices_include_tax);

    return ['id' => (string) Str::uuid7(), 'device_seq' => $seq, 'occurred_at' => now()->toIso8601String(), 'price_list_id' => $list->id, 'shift_id' => $shiftId,
        'lines' => [['product_id' => $t->bread->id, 'product_uom_id' => $unit->id, 'qty' => '1', 'unit_price_minor' => $price->amount_minor, 'line_total_minor' => $calc['gross']]],
        'totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $calc['gross']],
        'payments' => [['method' => 'cash', 'amount_minor' => $calc['gross'], 'tendered_minor' => $calc['gross']]]];
}

it('lets the till added in place of a wiped one continue its open shift, with a manager\'s PIN', function () {
    [$old, $oldToken] = lostTillDevice($this, 'Front PC');
    $first = lostTillLogin($this, $oldToken);
    $shiftId = $this->withToken($first)->postJson('/api/v1/pos/shifts/open', ['opening_float_minor' => 10000])->assertCreated()->json('shift.id');
    $this->withToken($first)->postJson('/api/v1/pos/sales', lostTillSale($this, $shiftId, 1))->assertCreated();

    // The till is wiped and added again as a new device. While the old one was just heard from, nothing is offered.
    [$new, $newToken] = lostTillDevice($this, 'Front PC (again)');
    $second = lostTillLogin($this, $newToken);
    expect($this->withToken($second)->getJson('/api/v1/pos/bootstrap')->assertOk()->json('left_open'))->toBeNull();

    // A quarter of an hour without a word from it: the shift is offered here.
    $this->travel(16)->minutes();
    $second = lostTillLogin($this, $newToken);
    $boot = $this->withToken($second)->getJson('/api/v1/pos/bootstrap')->assertOk()->json();
    expect($boot['shift'])->toBeNull()->and($boot['left_open'])->toMatchArray(['id' => $shiftId, 'opened_by' => $this->teller->name, 'sales' => 1, 'device' => 'Front PC']);

    // A manager approves; the teller's own PIN does not (no cash set-up), nor a wrong one.
    $ask = fn (string $user, string $pin) => $this->withToken($second)->postJson('/api/v1/pos/shifts/take-over',
        ['shift_id' => $shiftId, 'reason' => 'Till reinstalled', 'approval' => ['username' => $user, 'pin' => $pin]]);
    $ask('kofi', '0000')->assertStatus(422);
    $ask('esi', '4826')->assertStatus(422);
    expect($ask('kofi', '7391')->assertOk()->json('shift.id'))->toBe($shiftId);
    $shift = Shift::find($shiftId);
    expect($shift->device_id)->toBe($new->id)->and($shift->taken_over_from_device_id)->toBe($old->id)
        ->and(DB::table('audit_events')->where('action', 'shift.taken_over')->where('subject_id', $shiftId)->value('reason'))->toBe('Till reinstalled (approved by '.$this->manager->name.')');

    // It is this till's shift now: it sells and closes here; what the old device still sends finds the shift.
    $boot = $this->withToken($second)->getJson('/api/v1/pos/bootstrap')->assertOk()->json();
    expect($boot['shift']['id'])->toBe($shiftId)->and($boot['left_open'])->toBeNull();
    expect(ShiftService::resolve(Device::find($old->id), $shiftId)?->id)->toBe($shiftId);
    $this->withToken($second)->postJson('/api/v1/pos/sales', lostTillSale($this, $shiftId, 1))->assertCreated();
    $closed = $this->withToken($second)->postJson('/api/v1/pos/shifts/close', ['counted_cash_minor' => 20000])->assertOk()->json('shift');
    expect($closed['over_short_minor'])->toBe(0); // float 100.00 + two loaves at 50.00

    // Taken over once: nothing more to continue.
    $ask('kofi', '7391')->assertStatus(422);
});

it('offers a revoked till\'s shift at once, and never a shift of a till still in use', function () {
    [$old, $oldToken] = lostTillDevice($this, 'Front PC');
    $shiftId = $this->withToken(lostTillLogin($this, $oldToken))->postJson('/api/v1/pos/shifts/open', ['opening_float_minor' => 0])->assertCreated()->json('shift.id');
    [, $newToken] = lostTillDevice($this, 'New PC');
    $second = lostTillLogin($this, $newToken);
    expect($this->withToken($second)->getJson('/api/v1/pos/bootstrap')->json('left_open'))->toBeNull();

    $it = withMfa(staff($this->owner, 'it', 5, 'it_admin', ScopeType::Tenant, $this->tenant->id));
    app(DeviceService::class)->revoke(actorFor($it), Device::find($old->id), 'Replaced');
    expect($this->withToken($second)->getJson('/api/v1/pos/bootstrap')->json('left_open.id'))->toBe($shiftId);
});

it('lets a manager close a shift on a cash-control vault in the back office; the cashier then receives the vault', function () {
    app(Settings::class)->set(actorFor($this->owner), 'cash.vaults', true);
    $cash = app(CashControl::class);
    $cash->ensureVaults($this->tenant->id);
    $vault = Vault::where('till_id', $this->till->id)->whereHas('shiftTemplate', fn ($q) => $q->where('name', 'Morning'))->firstOrFail();
    $cashier = staff($this->owner, 'yaw', 3, 'cashier', ScopeType::Branch, $this->branches['HQ']->id);
    $session = $cash->issueFloat(actorFor($cashier), $vault, $this->teller, 20000);
    [, $token] = lostTillDevice($this, 'Front PC');
    $teller = lostTillLogin($this, $token);
    $shiftId = $this->withToken($teller)->postJson('/api/v1/pos/shifts/open', ['opening_float_minor' => 0, 'vault_session_id' => $session->id])->assertCreated()->json('shift.id');
    $this->withToken($teller)->postJson('/api/v1/pos/sales', lostTillSale($this, $shiftId, 1))->assertCreated();
    app('auth')->forgetGuards();

    // The back office offers Close shift, without the expected figure (the count stays blind).
    $this->actingAs($this->owner, 'web');
    $shift = Shift::find($shiftId);
    $detail = app(ShiftsScreen::class)->shift($shift);
    expect($detail['actions']['close'])->toBeTrue()->and($detail['expected_now'])->toBeNull()->and($detail['cash_vault'])->toBeTrue();

    $closed = $this->shifts->closeFromOffice(actorFor($this->owner), $shift, 25000, 'Till wiped during the shift');
    expect($closed->status)->toBe('closed')->and($closed->expected_cash_minor)->toBe(25000);
    $session->refresh();
    expect($session->status)->toBe('counted')->and($session->teller_count_minor)->toBe(25000)->and($session->expected_minor)->toBe(25000);
    expect($cash->receiveVault(actorFor($cashier), $session, null, 25000)['status'])->toBe('received');
});
