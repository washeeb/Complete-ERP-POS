<?php

use App\Domain\Access\AccessService;
use App\Domain\Devices\DeviceService;
use App\Domain\Messaging\PhonePush;
use App\Domain\Pos\TillApprovals;
use App\Filament\Pages\TillRequests;
use App\Models\PushSubscription;
use App\Models\Till;
use App\Models\TillApproval;
use App\Models\TillDrawerEvent;
use App\Security\ScopeType;
use Illuminate\Support\Str;
use Livewire\Livewire;

// Owner request 2026-11-15: a branch manager approves till actions from wherever they are, so their PIN is not typed at
// the till; a manager at the till can still approve there, with their staff card and PIN.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->till = Till::where('branch_id', $this->branches['HQ']->id)->first();
    [$device, $code] = app(DeviceService::class)->register(actorFor($this->owner),
        ['branch_id' => $this->branches['HQ']->id, 'till_id' => $this->till->id, 'name' => 'Till PC', 'kind' => 'pos']);
    $deviceToken = $this->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->assertCreated()->json('token');
    $this->cashier = staff($this->owner, 'esi', 2, 'teller', ScopeType::Till, $this->till->id);
    $this->supervisor = staff($this->owner, 'kojo', 3, 'supervisor', ScopeType::Branch, $this->branches['HQ']->id);
    $this->manager = staff($this->owner, 'ama', 4, 'branch_manager', ScopeType::Branch, $this->branches['HQ']->id);
    foreach ([[$this->cashier, '4826'], [$this->supervisor, '7391'], [$this->manager, '5602']] as [$u, $pin]) {
        app(AccessService::class)->setPin(actorFor($this->owner), $u, $pin);
    }
    $this->token = $this->withToken($deviceToken)->postJson('/api/v1/pos/login', ['username' => 'esi', 'pin' => '4826'])->assertOk()->json('token');
    app('auth')->forgetGuards();
    $this->withToken($this->token)->postJson('/api/v1/pos/shifts/open', ['opening_float_minor' => 50000])->assertCreated();
    $this->api = fn () => $this->withToken($this->token);
    $this->ask = fn (array $body = []) => ($this->api)()->postJson('/api/v1/pos/approvals', $body + ['kind' => 'no_sale', 'summary' => 'Open the drawer without a sale', 'reason' => 'Change for a customer']);
    $this->noSale = fn (array $approval) => ($this->api)()->postJson('/api/v1/pos/drawer', ['id' => (string) Str::uuid7(), 'kind' => 'no_sale', 'reason' => 'Change for a customer', 'approval' => $approval]);
});

it('lets the manager approve from their phone; the till goes on once with that approval', function () {
    $id = ($this->ask)()->assertCreated()->assertJsonPath('approval.status', 'pending')->json('approval.id');
    expect($this->manager->notifications()->count())->toBe(1)
        ->and($this->supervisor->notifications()->count())->toBe(0) // a supervisor cannot open the drawer without a sale
        ->and($this->cashier->notifications()->count())->toBe(0);

    $this->actingAs(withMfa($this->manager->fresh()), 'web');
    Livewire::test(TillRequests::class, ['id' => $id])->assertCanSeeTableRecords([TillApproval::find($id)])
        ->callTableAction('approve', TillApproval::find($id))->assertNotified('Approved: the till goes on');

    ($this->api)()->getJson("/api/v1/pos/approvals/{$id}")->assertOk()->assertJsonPath('approval.status', 'approved')->assertJsonPath('approval.decided_by', $this->manager->name);
    ($this->noSale)(['request_id' => $id])->assertCreated()->assertJsonPath('event.approved_by', $this->manager->name);
    ($this->noSale)(['request_id' => $id])->assertStatus(422)->assertJson(['error' => 'approval_failed']); // once only
    expect(TillDrawerEvent::count())->toBe(1)->and(TillApproval::find($id)->status)->toBe('used');
});

it('tells the till when the manager declines, with why', function () {
    $id = ($this->ask)()->json('approval.id');
    $this->actingAs(withMfa($this->manager->fresh()), 'web');
    Livewire::test(TillRequests::class, ['id' => $id])->callTableAction('decline', TillApproval::find($id), ['note' => 'Use the float bag'])->assertNotified('Declined');

    ($this->api)()->getJson("/api/v1/pos/approvals/{$id}")->assertJsonPath('approval.status', 'declined')->assertJsonPath('approval.note', 'Use the float bag');
    ($this->noSale)(['request_id' => $id])->assertStatus(422)->assertJson(['error' => 'approval_failed']);
});

it('lets a manager at the till approve with their staff card and PIN, never the cashier or someone without the right', function () {
    $id = ($this->ask)()->json('approval.id');
    ($this->api)()->postJson("/api/v1/pos/approvals/{$id}/here", ['username' => 'esi', 'pin' => '4826'])->assertStatus(422);
    ($this->api)()->postJson("/api/v1/pos/approvals/{$id}/here", ['username' => 'kojo', 'pin' => '7391'])->assertStatus(422)->assertJson(['error' => 'over_limit']);
    $card = app(AccessService::class)->issueBadge(actorFor($this->owner), $this->manager);
    ($this->api)()->postJson("/api/v1/pos/approvals/{$id}/here", ['username' => $card, 'pin' => '5602'])->assertOk()->assertJsonPath('approval.status', 'approved');
    expect(TillApproval::find($id)->decided_via)->toBe('till');
    ($this->noSale)(['request_id' => $id])->assertCreated();

    // The card also works in the usual approval fields, in place of the username.
    ($this->noSale)(['username' => $card, 'pin' => '5602'])->assertCreated()->assertJsonPath('event.approved_by', $this->manager->name);
});

it('runs out after ten minutes, and an approval only covers what was asked', function () {
    $id = ($this->ask)()->json('approval.id');
    $this->travel(11)->minutes();
    ($this->api)()->getJson("/api/v1/pos/approvals/{$id}")->assertJsonPath('approval.status', 'expired');
    $this->travelBack();

    // Approved for a no-sale, it cannot be spent on a cash drop.
    $id = ($this->ask)()->json('approval.id');
    app(TillApprovals::class)->decide(actorFor($this->manager), TillApproval::find($id), true);
    ($this->api)()->postJson('/api/v1/pos/drawer', ['id' => (string) Str::uuid7(), 'kind' => 'drop', 'amount_minor' => 20000, 'approval' => ['request_id' => $id]])
        ->assertStatus(422)->assertJson(['error' => 'approval_failed']);

    // The manager cannot approve on the page what they could not approve at the till.
    $other = ($this->ask)()->json('approval.id');
    expect(app(TillApprovals::class)->mayDecide($this->supervisor, TillApproval::find($other)))->toBeFalse()
        ->and(app(TillApprovals::class)->mayDecide($this->cashier, TillApproval::find($other)))->toBeFalse();
});

it('sends the request to the phones the manager turned notifications on for', function () {
    PhonePush::$sent = [];
    $this->actingAs(withMfa($this->manager->fresh()), 'web');
    Livewire::test(TillRequests::class)->assertSee('Turn on notifications')
        ->call('savePush', 'https://fcm.googleapis.com/fcm/send/abc123', str_repeat('B', 87), str_repeat('a', 22), 'Chrome on Android')
        ->assertNotified('Notifications are on for this device');
    expect(PushSubscription::where('user_id', $this->manager->id)->count())->toBe(1);

    ($this->ask)()->assertCreated();
    expect(PhonePush::$sent)->toHaveCount(1)
        ->and(PhonePush::$sent[0]['users'])->toBe([$this->manager->id])
        ->and(PhonePush::$sent[0]['url'])->toContain('/office/till-requests?id=');

    // Turned off for approvals in the profile: the bell only.
    $this->manager->forceFill(['notify_prefs' => ['approvals' => ['in_app' => true, 'push' => false]]])->save();
    PhonePush::$sent = [];
    ($this->ask)()->assertCreated();
    expect(PhonePush::$sent)->toBe([]);
    PhonePush::$sent = null;
});
