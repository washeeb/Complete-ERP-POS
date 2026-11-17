<?php

use App\Domain\Inventory\InventoryService;
use App\Models\StockLevel;
use App\Models\StockTransfer;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;

// Transfers in the new back office (release 3, part 4): the draft form, dispatch, receive and cancel through
// TransferService, so stock leaves at dispatch, arrives when received, and a difference on arrival is flagged.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner, [
        ['code' => 'HQ', 'name' => 'Head Office', 'is_hq' => true, 'tills' => 1, 'modules' => ['retail_wholesale']],
        ['code' => 'BA', 'name' => 'Branch A', 'is_hq' => false, 'tills' => 1, 'modules' => ['retail_wholesale']],
    ]);
    $this->hq = Warehouse::find(Till::where('branch_id', $this->branches['HQ']->id)->value('warehouse_id'));
    $this->ba = Warehouse::find(Till::where('branch_id', $this->branches['BA']->id)->value('warehouse_id'));
    $this->milo = makeProduct($this->owner, 'MILO-400', 'Milo 400g', retail: '45.00');
    $this->pc = $this->milo->units->firstWhere('is_base', true);
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->hq, $this->milo, $this->pc, '40', 'opening', '', null, '30.00');
    $this->owner = withMfa($this->owner->fresh());
    $this->at = fn (Warehouse $w) => StockLevel::where('warehouse_id', $w->id)->where('product_id', $this->milo->id)->value('on_hand');
});

it('makes a draft, dispatches it, and receives what arrived — a difference is flagged', function () {
    $this->actingAs($this->owner, 'web');
    expect($this->getJson('/office-api/transfers/form')->assertOk()->json('choices.from'))->not->toBeEmpty();
    $id = $this->postJson('/office-api/transfers', ['values' => ['from_warehouse_id' => $this->hq->id, 'to_warehouse_id' => $this->ba->id, 'note' => 'Weekly top-up',
        'lines' => [['product_uom_id' => $this->pc->id, 'qty' => '10']]]])->assertOk()->json('id');
    $doc = $this->getJson("/office-api/transfers/{$id}")->assertOk()->json();
    expect($doc['status'])->toBe('draft')->and($doc['lines'][0]['sent'])->toBe('10')->and($doc['actions']['dispatch'])->toBeTrue()->and($doc['actions']['print'])->toContain('/print/transfer/');

    $doc = $this->postJson("/office-api/transfers/{$id}/action", ['action' => 'dispatch'])->assertOk()->json();
    expect($doc['status'])->toBe('in_transit')->and($doc['actions']['receive'])->toBeTrue()->and(($this->at)($this->hq))->toBe('30.000');
    expect($this->getJson('/office-api/transfers')->json('to_receive'))->toBe(1);
    $this->getJson("/office-api/transfers/{$id}/form")->assertForbidden(); // dispatched: no longer a draft

    $line = $doc['lines'][0]['id'];
    $doc = $this->postJson("/office-api/transfers/{$id}/action", ['action' => 'receive', 'received' => [$line => '9'], 'note' => 'One pack crushed'])->assertOk()->json();
    expect($doc['status'])->toBe('received')->and($doc['difference'])->toBeTrue()->and($doc['lines'][0]['received'])->toBe('9')->and(($this->at)($this->ba))->toBe('9.000');
    expect($this->getJson('/office-api/transfers?difference=1')->json('total'))->toBe(1)
        ->and($this->get('/office-api/export/transfers?format=csv')->streamedContent())->toContain(StockTransfer::find($id)->number);
});

it('refuses the same warehouse twice, cancels a draft with a reason, and keeps transfers from a teller', function () {
    $this->actingAs($this->owner, 'web');
    $this->postJson('/office-api/transfers', ['values' => ['from_warehouse_id' => $this->hq->id, 'to_warehouse_id' => $this->hq->id,
        'lines' => [['product_uom_id' => $this->pc->id, 'qty' => '1']]]])->assertStatus(422);
    $id = $this->postJson('/office-api/transfers', ['values' => ['from_warehouse_id' => $this->hq->id, 'to_warehouse_id' => $this->ba->id,
        'lines' => [['product_uom_id' => $this->pc->id, 'qty' => '2']]]])->assertOk()->json('id');
    $this->postJson("/office-api/transfers/{$id}/action", ['action' => 'cancel'])->assertStatus(422);
    $this->postJson("/office-api/transfers/{$id}/action", ['action' => 'cancel', 'reason' => 'Not needed'])->assertOk()->assertJsonPath('status', 'cancelled');
    expect(($this->at)($this->hq))->toBe('40.000');

    $teller = withMfa(staff($this->owner, 'esi', 2, 'teller', ScopeType::Branch, $this->branches['HQ']->id));
    $this->actingAs($teller, 'web');
    $this->getJson('/office-api/transfers')->assertForbidden();
});
