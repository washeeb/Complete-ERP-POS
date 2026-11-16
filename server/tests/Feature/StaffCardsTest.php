<?php

use App\Domain\Access\AccessService;
use App\Domain\Devices\DeviceService;
use App\Filament\Pages\StaffCards;
use App\Models\Till;
use App\Security\ScopeType;
use App\Support\Settings;
use App\Support\StaffCard;
use Livewire\Livewire;

// Owner request 2026-11-15: staff cards generated and printed straight away (A4 sheet or PVC card printer), scanned at the
// till instead of typing; by default the PIN is still typed, and the company may let the card alone sign in.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->till = Till::where('branch_id', $this->branches['HQ']->id)->first();
    $this->esi = staff($this->owner, 'esi', 2, 'teller', ScopeType::Till, $this->till->id);
    $this->kofi = staff($this->owner, 'kofi', 2, 'teller', ScopeType::Branch, $this->branches['HQ']->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $this->esi, '4826');
    $this->owner = withMfa($this->owner->fresh());
});

it('prints new cards for the people ticked, once, with their name, role and a barcode but never the code as text', function () {
    $this->actingAs($this->owner, 'web');
    Livewire::test(StaffCards::class)->assertCanSeeTableRecords([$this->esi, $this->kofi])
        ->callTableBulkAction('print', [$this->esi, $this->kofi], ['layout' => 'card'])->assertHasNoTableBulkActionErrors();
    expect($this->esi->fresh()->badge_hash)->not->toBeNull()->and($this->kofi->fresh()->badge_hash)->not->toBeNull();

    // The print page: made by the person who made the cards, once.
    $old = $this->esi->fresh()->badge_hash;
    $url = StaffCard::issue(actorFor($this->owner), [$this->esi->fresh()], 'a4');
    expect($this->esi->fresh()->badge_hash)->not->toBe($old); // the earlier card stopped

    $other = staff($this->owner, 'ama', 4, 'branch_manager', ScopeType::Tenant, $this->tenant->id);
    $this->flushSession();
    $this->actingAs($other, 'web')->get($url)->assertOk()->assertSee('already printed'); // someone else's link: nothing
    $this->flushSession();
    $page = $this->actingAs($this->owner, 'web')->get($url)->assertOk()->assertSee('Esi')->assertSee('Teller')->assertSee('<svg', false)->getContent();
    expect($page)->not->toContain('SLB1-');
    $this->get($url)->assertOk()->assertSee('already printed'); // once only
});

it('signs in with the card and PIN by default, and with the card alone only where the company allows it', function () {
    [$device, $code] = app(DeviceService::class)->register(actorFor($this->owner),
        ['branch_id' => $this->branches['HQ']->id, 'till_id' => $this->till->id, 'name' => 'Till PC', 'kind' => 'pos']);
    $token = $this->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->assertCreated()->json('token');
    $card = app(AccessService::class)->issueBadge(actorFor($this->owner), $this->esi);
    expect(strlen($card))->toBe(19); // short enough for a barcode any scanner reads
    $login = fn (string $user, string $pin) => $this->withToken($token)->postJson('/api/v1/pos/login', ['username' => $user, 'pin' => $pin]);

    $login($card, '')->assertStatus(422)->assertJson(['error' => 'pin_required']);
    $login('esi', '')->assertStatus(422)->assertJson(['error' => 'pin_required']);
    expect($this->esi->fresh()->pin_failed_attempts)->toBe(0); // asking for the PIN is not a wrong PIN
    $login($card, '4826')->assertOk()->assertJsonPath('offline_grant.card_only', false);
    app('auth')->forgetGuards();

    app(Settings::class)->set(actorFor($this->owner), 'pos.card_only_sign_in', true);
    $login($card, '')->assertOk()->assertJsonPath('user.name', $this->esi->name)->assertJsonPath('offline_grant', null);
    app('auth')->forgetGuards();
    $login($card, '4826')->assertOk()->assertJsonPath('offline_grant.card_only', true);
    app('auth')->forgetGuards();

    app(AccessService::class)->revokeBadge(actorFor($this->owner), $this->esi);
    $login($card, '')->assertStatus(401)->assertJson(['error' => 'invalid_credentials']); // a stopped card
});
