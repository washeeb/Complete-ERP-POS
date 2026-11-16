<?php

use App\Domain\Devices\DeviceService;
use App\Domain\Pos\DrawerService;
use App\Domain\Pos\ShiftService;
use App\Models\Shift;
use App\Models\Till;
use App\Security\ScopeType;
use Illuminate\Support\Str;

// Till shifts and the till drawer in the new back office (release 2): the current screens' figures and actions.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $till = Till::where('branch_id', $this->branches['HQ']->id)->first();
    [$this->device] = app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $this->branches['HQ']->id, 'till_id' => $till->id, 'name' => 'T1', 'kind' => 'pos']);
    $this->device->forceFill(['status' => 'active'])->save();
    $this->teller = staff($this->owner, 'janet', 2, 'teller', ScopeType::Till, $till->id);
    $this->shifts = app(ShiftService::class);
    $this->second = fn () => staff($this->owner, 'abena', 2, 'teller', ScopeType::Till, $till->id);
    $this->owner = withMfa($this->owner->fresh());
});

function shiftOpenClose($t, int $float, int $counted): Shift
{
    $t->shifts->open($t->device, $t->teller, $float);

    return $t->shifts->close($t->device, $t->teller, $counted);
}

it('lists the shifts with the current screen\'s figures, and filters them', function () {
    $closed = shiftOpenClose($this, 10000, 14000); // GH₵ 40.00 over
    $open = $this->shifts->open($this->device, ($this->second)(), 5000); // another teller (one shift per person a day)
    $this->actingAs($this->owner, 'web');
    $r = $this->getJson('/office-api/shifts')->assertOk()->json();
    expect($r['total'])->toBe(2)->and($r['open'])->toBe(1)->and($r['over_short'])->toBe(4000)
        ->and(collect($r['rows'])->firstWhere('id', $closed->id))->toMatchArray(['status' => 'closed', 'float' => 10000, 'counted' => 14000, 'over_short' => 4000, 'opened_by' => $this->teller->name]);
    $ids = fn (array $q) => collect($this->getJson('/office-api/shifts?'.http_build_query($q))->assertOk()->json('rows'))->pluck('id')->all();
    expect($ids(['status' => 'open']))->toBe([$open->id])
        ->and($ids(['os_min' => '10']))->toBe([$closed->id])
        ->and($ids(['teller' => $this->teller->id]))->toBe([$closed->id])
        ->and($ids(['from' => now()->addDay()->toDateString()]))->toBe([]);
});

it('shows one shift with the shift report\'s figures, and prints the report', function () {
    $closed = shiftOpenClose($this, 10000, 14000);
    $this->actingAs($this->owner, 'web');
    $s = $this->getJson("/office-api/shifts/{$closed->id}")->assertOk()->json();
    expect(collect($s['tiles'])->pluck('label'))->toContain('Grand total sold')->toContain('Over / short')
        ->and($s['facts']['Teller'])->toBe($this->teller->name)
        ->and($s['actions'])->toMatchArray(['report' => true, 'close' => false, 'correct' => true]);
    $this->get("/office-api/shifts/{$closed->id}/report")->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('corrects a mistyped count and closes a shift no till can close, as the current screen does', function () {
    $closed = shiftOpenClose($this, 0, 400000); // GH₵ 4,000 typed, nothing there
    $this->actingAs($this->owner, 'web');
    $this->postJson("/office-api/shifts/{$closed->id}/correct", ['counted' => '0'])->assertUnprocessable(); // a reason is needed
    $this->postJson("/office-api/shifts/{$closed->id}/correct", ['counted' => '0', 'reason' => 'Typed 4000 by mistake'])->assertOk()
        ->assertJsonPath('counted', 0)->assertJsonPath('over_short', 0)->assertJsonPath('corrected', true);

    $open = $this->shifts->open($this->device, ($this->second)(), 0);
    $this->postJson("/office-api/shifts/{$open->id}/close", ['counted' => '0', 'reason' => 'Till reinstalled'])->assertOk()->assertJsonPath('status', 'closed');
    $this->postJson("/office-api/shifts/{$open->id}/close", ['counted' => '0', 'reason' => 'again'])->assertUnprocessable(); // already closed
});

it('keeps shifts to the branches the person may see', function () {
    $closed = shiftOpenClose($this, 0, 0);
    $other = collect($this->branches)->first(fn ($b) => $b->code !== 'HQ');
    $this->actingAs(withMfa(staff($this->owner, 'ama', 4, 'branch_manager', ScopeType::Branch, $other->id)), 'web');
    expect($this->getJson('/office-api/shifts')->assertOk()->json('total'))->toBe(0);
    $this->getJson("/office-api/shifts/{$closed->id}")->assertForbidden();
    $this->postJson("/office-api/shifts/{$closed->id}/correct", ['counted' => '1', 'reason' => 'x'])->assertForbidden();
});

it('lists the drawer: pay-outs, pay-ins, drops and no-sale opens, with their totals', function () {
    $this->shifts->open($this->device, $this->teller, 0);
    app(DrawerService::class)->record($this->device, $this->teller, ['id' => (string) Str::uuid7(), 'kind' => 'payin', 'amount_minor' => 2000, 'reason' => 'Change from the safe']);
    $this->actingAs($this->owner, 'web');
    $r = $this->getJson('/office-api/drawer')->assertOk()->json();
    expect($r['total'])->toBe(1)
        ->and($r['rows'][0])->toMatchArray(['kind' => 'payin', 'amount' => 2000, 'reason' => 'Change from the safe', 'by' => $this->teller->name])
        ->and(collect($r['kinds'])->firstWhere('id', 'payin'))->toMatchArray(['count' => 1, 'amount' => 2000]);
    expect($this->getJson('/office-api/drawer?kind=no_sale')->json('total'))->toBe(0);
    expect($this->getJson('/office-api/shifts/options')->assertOk()->json('kinds'))->not->toBeEmpty();
});
