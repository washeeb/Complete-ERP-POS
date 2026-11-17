<?php

use App\Domain\Inventory\InventoryService;
use App\Models\StockCount;
use App\Models\StockLevel;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;

// Stock counts in the new back office (release 3, part 4): start, enter counts, submit, approve and post, through
// StockCountService; a blind count hides the expected figures from counters until it is submitted.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Warehouse::find(Till::where('branch_id', $this->branches['HQ']->id)->value('warehouse_id'));
    $this->milo = makeProduct($this->owner, 'MILO-400', 'Milo 400g', retail: '45.00', carton: ['24', '1000.00']);
    $this->pc = $this->milo->units->firstWhere('is_base', true);
    $this->ctn = $this->milo->units->firstWhere('is_base', false);
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->hq, $this->milo, $this->pc, '40', 'opening', '', null, '30.00');
    $this->owner = withMfa($this->owner->fresh());
    $this->onHand = fn () => StockLevel::where('warehouse_id', $this->hq->id)->where('product_id', $this->milo->id)->value('on_hand');
});

it('starts a count, takes the counted quantities, and posts the difference once approved', function () {
    $this->actingAs($this->owner, 'web');
    expect($this->getJson('/office-api/counts/options')->assertOk()->json('can_start'))->toBeTrue();
    $id = $this->postJson('/office-api/counts', ['warehouse_id' => $this->hq->id, 'blind' => false, 'note' => 'Month end'])->assertOk()->json('id');

    // Counted in cartons first (1 = 24 pieces); a recount in pieces replaces it: 38 counted against 40 expected.
    $this->postJson("/office-api/counts/{$id}/record", ['items' => [['product_uom_id' => $this->ctn->id, 'qty' => '1']]])->assertOk();
    expect(collect($this->getJson("/office-api/counts/{$id}")->json('lines'))->firstWhere('sku', 'MILO-400')['counted'])->toBe('24');
    $doc = $this->postJson("/office-api/counts/{$id}/record", ['items' => [['product_uom_id' => $this->pc->id, 'qty' => '38']]])->assertOk()->json();
    $milo = collect($doc['lines'])->firstWhere('sku', 'MILO-400');
    expect($doc['shows_expected'])->toBeTrue()->and($milo['expected'])->toBe('40')->and($doc['counted'])->toBeGreaterThan(0);

    $this->postJson("/office-api/counts/{$id}/action", ['action' => 'submit'])->assertOk()->assertJsonPath('status', 'submitted');
    // Whoever submitted it may not approve it.
    $this->postJson("/office-api/counts/{$id}/action", ['action' => 'post'])->assertStatus(422)->assertJsonPath('error', 'own_document');
    $gm = withMfa(staff($this->owner, 'kwame', 5, 'general_manager', ScopeType::Tenant, $this->tenant->id));
    $this->actingAs($gm, 'web');
    $this->postJson("/office-api/counts/{$id}/action", ['action' => 'post'])->assertOk()->assertJsonPath('status', 'posted')->assertJsonPath('posted_lines', 1);
    expect(($this->onHand)())->toBe('38.000');
    $this->get("/office-api/counts/{$id}/sheet?format=csv")->assertOk();
    expect($this->getJson('/office-api/counts?status=posted')->json('total'))->toBe(1)
        ->and($this->get('/office-api/export/counts?format=csv')->streamedContent())->toContain(StockCount::find($id)->number);
});

it('hides the expected figures of a blind count from a counter until it is submitted', function () {
    $this->actingAs($this->owner, 'web');
    $id = $this->postJson('/office-api/counts', ['warehouse_id' => $this->hq->id, 'blind' => true])->assertOk()->json('id');
    $clerk = withMfa(staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->branches['HQ']->id));
    $this->actingAs($clerk, 'web');
    $doc = $this->getJson("/office-api/counts/{$id}")->assertOk()->json();
    expect($doc['shows_expected'])->toBeFalse()->and(collect($doc['lines'])->pluck('expected')->filter()->all())->toBe([]);
});

it('refuses a submit with nothing counted, a cancel without a reason, and someone who may not count', function () {
    $this->actingAs($this->owner, 'web');
    $id = $this->postJson('/office-api/counts', ['warehouse_id' => $this->hq->id, 'blind' => true])->assertOk()->json('id');
    $this->postJson("/office-api/counts/{$id}/action", ['action' => 'submit'])->assertStatus(422)->assertJsonPath('detail', 'Nothing has been counted yet.');
    $this->postJson("/office-api/counts/{$id}/action", ['action' => 'cancel'])->assertStatus(422);
    $this->postJson("/office-api/counts/{$id}/action", ['action' => 'cancel', 'reason' => 'Started by mistake'])->assertOk()->assertJsonPath('status', 'cancelled');

    $teller = withMfa(staff($this->owner, 'esi', 2, 'teller', ScopeType::Branch, $this->branches['HQ']->id));
    $this->actingAs($teller, 'web');
    $this->getJson('/office-api/counts')->assertForbidden();
    $this->postJson('/office-api/counts', ['warehouse_id' => $this->hq->id])->assertForbidden();
});
