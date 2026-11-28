<?php

use App\Domain\Access\AccessService;
use App\Domain\Devices\DeviceService;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Till;
use App\Security\ScopeType;
use Illuminate\Support\Str;

// Till new look, part 1b: Sales → Till favourites. The products a branch's tills show first, in the order chosen; set
// by whoever may change the branch's till settings (pos.settings), in their branches only; sent with the till catalog.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner); // HQ (retail & wholesale) and BA (grocery)
    $this->owner = withMfa($this->owner);
    $this->milo = makeProduct($this->owner, 'MILO-400', 'Milo 400g', retail: '45.00');
    $this->bread = makeProduct($this->owner, 'BREAD', 'Sliced bread', retail: '18.50');
});

/** The till catalog of a till in this branch, as a teller there gets it. */
function favouritesCatalog(object $t, Branch $branch): array
{
    app('auth')->forgetGuards(); // a till signs in by its own token, not the back-office session of this test
    $till = Till::where('branch_id', $branch->id)->first();
    [, $code] = app(DeviceService::class)->register(actorFor($t->owner), ['branch_id' => $branch->id, 'till_id' => $till->id, 'name' => 'Till PC', 'kind' => 'pos']);
    $deviceToken = test()->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->assertCreated()->json('token');
    $teller = staff($t->owner, 'esi', 2, 'teller', ScopeType::Till, $till->id);
    app(AccessService::class)->setPin(actorFor($t->owner), $teller, '4826');
    $token = test()->withToken($deviceToken)->postJson('/api/v1/pos/login', ['username' => 'esi', 'pin' => '4826'])->assertOk()->json('token');
    app('auth')->forgetGuards();

    return test()->withToken($token)->getJson('/api/v1/pos/catalog')->assertOk()->json();
}

it('keeps the favourite tiles of a branch in the order chosen and sends them to its tills', function () {
    $this->actingAs($this->owner, 'web');
    $page = $this->getJson('/office-api/till-favourites?branch='.$this->b['HQ']->id)->assertOk()->json();
    expect($page['branch'])->toBe($this->b['HQ']->id)->and($page['favourites'])->toBe([])->and($page['max'])->toBe(48);
    expect(collect($this->getJson('/office-api/till-favourites/products?branch='.$this->b['HQ']->id.'&q=mil')->json('results'))->pluck('label')->all())->toBe(['MILO 400G']);

    $saved = $this->postJson('/office-api/till-favourites/'.$this->b['HQ']->id, ['products' => [$this->bread->id, $this->milo->id]])->assertOk()->json('favourites');
    expect(collect($saved)->pluck('name')->all())->toBe(['SLICED BREAD', 'MILO 400G'])->and($saved[1]['price'])->toContain('45.00')
        ->and(AuditEvent::where('action', 'branch.till_favourites_changed')->exists())->toBeTrue()
        ->and($this->b['HQ']->fresh()->till_favourites)->toBe([$this->bread->id, $this->milo->id]);
    $urls = collect($this->getJson('/office-api/menu')->json('tabs'))->flatMap(fn ($t) => collect($t['sections'])->flatMap(fn ($s) => collect($s['groups'])->flatMap(fn ($g) => collect($g['items'])->pluck('url'))));
    expect($urls)->toContain('/office/till-favourites');

    expect(favouritesCatalog($this, $this->b['HQ'])['favourites'])->toBe([$this->bread->id, $this->milo->id]); // the tills get them, in order
});

it('refuses more than a screen of tiles, and products the branch does not sell', function () {
    $this->actingAs($this->owner, 'web');
    $this->postJson('/office-api/till-favourites/'.$this->b['HQ']->id, ['products' => array_map(fn () => (string) Str::uuid7(), range(1, 49))])->assertStatus(422);
    $this->postJson('/office-api/till-favourites/'.$this->b['HQ']->id, ['products' => [(string) Str::uuid7()]])->assertStatus(422)
        ->assertJsonPath('detail', 'One of these products is not sold at this branch (another industry, or no longer in use).');
});

it('lets a branch manager set their own branch only, and nobody without till settings', function () {
    $manager = withMfa(staff($this->owner, 'mgr', 4, 'branch_manager', ScopeType::Branch, $this->b['BA']->id));
    $this->actingAs($manager, 'web');
    expect(collect($this->getJson('/office-api/till-favourites')->assertOk()->json('branches'))->pluck('id')->all())->toBe([$this->b['BA']->id]);
    $this->postJson('/office-api/till-favourites/'.$this->b['HQ']->id, ['products' => []])->assertNotFound();

    $clerk = withMfa(staff($this->owner, 'clerk', 2));
    $this->actingAs($clerk, 'web');
    $this->getJson('/office-api/till-favourites')->assertForbidden();
});
