<?php

use App\Domain\Access\AccessService;
use App\Domain\Devices\DeviceService;
use App\Domain\Expenses\ExpenseService;
use App\Domain\Pos\TillApprovals;
use App\Models\Account;
use App\Models\Till;
use App\Models\TillApproval;
use App\Security\Reconfirm;
use App\Security\ScopeType;

// To-do in the new back office (release 1): everything waiting for the person's decision, item by item; till requests
// answered right there.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->till = Till::where('branch_id', $this->branches['HQ']->id)->first();
    [$this->device] = app(DeviceService::class)->register(actorFor($this->owner),
        ['branch_id' => $this->branches['HQ']->id, 'till_id' => $this->till->id, 'name' => 'Till PC', 'kind' => 'pos']);
    $this->cashier = staff($this->owner, 'esi', 2, 'teller', ScopeType::Till, $this->till->id);
    $this->manager = withMfa(staff($this->owner, 'ama', 4, 'branch_manager', ScopeType::Branch, $this->branches['HQ']->id));
    app(AccessService::class)->setPin(actorFor($this->owner), $this->cashier, '4826');
    $this->request = app(TillApprovals::class)->ask($this->device, $this->cashier, 'no_sale', 0, 0, 'Open the drawer without a sale', null, 'Change for a customer');
});

it('lists till requests first, item by item, only for those who may decide them', function () {
    $this->actingAs($this->manager, 'web');
    $todo = $this->getJson('/office-api/todo')->assertOk()->json();
    expect($todo['groups'][0]['key'])->toBe('till')
        ->and($todo['groups'][0]['items'][0])->toMatchArray(['id' => $this->request->id, 'title' => 'Open the drawer without a sale', 'who' => $this->cashier->name])
        ->and($todo['total'])->toBeGreaterThanOrEqual(1);
    $this->getJson('/office-api/todo-count')->assertOk()->assertJsonPath('count', $todo['total']);

    $this->flushSession();
    $this->actingAs($this->cashier, 'web');
    expect(collect($this->getJson('/office-api/todo')->assertOk()->json('groups'))->pluck('key'))->not->toContain('till'); // never their own
});

it('approves a till request from To-do, after the person confirms it is them', function () {
    $this->actingAs($this->manager, 'web');
    Reconfirm::$assumeConfirmed = false;
    $this->postJson("/office-api/till-requests/{$this->request->id}/decide", ['yes' => true])->assertStatus(428)
        ->assertJsonPath('error', 'reconfirm')->assertJsonPath('url', '/office/confirm-its-you');
    expect($this->request->fresh()->status)->toBe('pending');

    Reconfirm::$assumeConfirmed = true;
    $this->postJson("/office-api/till-requests/{$this->request->id}/decide", ['yes' => true])->assertOk()->assertJsonPath('status', 'approved');
    $this->postJson("/office-api/till-requests/{$this->request->id}/decide", ['yes' => false, 'note' => 'x'])->assertStatus(422); // already answered
});

it('needs a reason to decline, and refuses someone who may not decide', function () {
    $this->actingAs($this->manager, 'web');
    $this->postJson("/office-api/till-requests/{$this->request->id}/decide", ['yes' => false])->assertStatus(422);
    $this->postJson("/office-api/till-requests/{$this->request->id}/decide", ['yes' => false, 'note' => 'Use the float bag'])->assertOk()->assertJsonPath('status', 'declined');

    $other = app(TillApprovals::class)->ask($this->device, $this->cashier, 'no_sale', 0, 0, 'Open the drawer again', null, null);
    $this->flushSession();
    $this->actingAs(withMfa(staff($this->owner, 'kojo', 3, 'supervisor', ScopeType::Branch, $this->branches['HQ']->id)), 'web');
    $this->postJson("/office-api/till-requests/{$other->id}/decide", ['yes' => true])->assertForbidden(); // a supervisor may not approve a no-sale
    expect(TillApproval::find($other->id)->status)->toBe('pending');
});

it('lists the documents waiting for approval, with their number, who asked and the amount', function () {
    // The branch manager claims a taxi fare; the owner approves expense claims.
    $account = Account::where('type', 'expense')->where('is_header', false)->where('is_active', true)->whereNull('control')->first();
    $claim = app(ExpenseService::class)->submit(actorFor($this->manager), $this->branches['HQ'], ['spent_on' => now()->toDateString(), 'account_id' => $account->id,
        'description' => 'Taxi to the bank', 'amount_minor' => 4500]);
    $this->actingAs($this->owner = withMfa($this->owner->fresh()), 'web');
    $claims = collect($this->getJson('/office-api/todo')->assertOk()->json('groups'))->firstWhere('key', 'claim');
    expect($claims)->not->toBeNull()
        ->and($claims['items'][0])->toMatchArray(['id' => $claim->id, 'title' => $claim->number, 'amount' => 4500])
        ->and($claims['items'][0]['url'])->toStartWith('/office/expense-claims/');
});
