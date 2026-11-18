<?php

use App\Domain\Inventory\InventoryService;
use App\Models\Industry;
use App\Models\StockLevel;
use App\Models\StockRequest;
use App\Models\Warehouse;
use App\Security\ScopeType;

// Stock requests in the new back office (release 3, part 5): ask, approve on both sides between industries, send (which
// creates and dispatches a transfer), reject and cancel — all through StockRequestService.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner, [
        ['code' => 'HQ', 'name' => 'Head Office', 'is_hq' => true, 'tills' => 0, 'modules' => []],
        ['code' => 'P1', 'name' => 'Pharmacy Osu', 'is_hq' => false, 'tills' => 0, 'modules' => ['pharmacy']],
        ['code' => 'P2', 'name' => 'Pharmacy Tema', 'is_hq' => false, 'tills' => 0, 'modules' => ['pharmacy']],
        ['code' => 'G1', 'name' => 'Grocery Spintex', 'is_hq' => false, 'tills' => 0, 'modules' => ['grocery']],
    ]);
    $this->wh = collect($this->b)->map(fn ($b) => Warehouse::where('branch_id', $b->id)->first());
    $pharmacy = Industry::where('module_key', 'pharmacy')->value('id');
    $grocery = Industry::where('module_key', 'grocery')->value('id');
    $item = function (string $sku, string $name, ?string $industry, ?string $stockAt = null) {
        $p = makeProduct($this->owner, $sku, $name, retail: '5.00');
        $p->forceFill(['industry_id' => $industry])->saveQuietly();
        $p = $p->fresh();
        if ($stockAt) {
            app(InventoryService::class)->adjust(actorFor($this->owner), $this->wh[$stockAt], $p, $p->units->first(), '50', 'opening', '', null, '2.00');
        }

        return $p->fresh();
    };
    $this->para = $item('PCM', 'Paracetamol 500mg', $pharmacy, 'P1');
    $this->waterPh = $item('PH-WATER', 'Bottled water (pharmacy)', $pharmacy, 'P1');
    $this->waterGr = $item('GR-WATER', 'Bottled water', $grocery);
    $this->unit = fn ($p) => $p->units->firstWhere('is_base', true)->id;
    $this->at = fn (string $w, $p) => StockLevel::where('warehouse_id', $this->wh[$w]->id)->where('product_id', $p->id)->value('on_hand');
    $this->p2Keeper = withMfa(staff($this->owner, 'efua', 2, 'storekeeper', ScopeType::Branch, $this->b['P2']->id));
    $this->p1Keeper = withMfa(staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->b['P1']->id));
    $this->p1Mgr = withMfa(staff($this->owner, 'kojo', 4, 'branch_manager', ScopeType::Branch, $this->b['P1']->id));
    $this->g1Keeper = withMfa(staff($this->owner, 'ama', 2, 'storekeeper', ScopeType::Branch, $this->b['G1']->id));
    $this->g1Mgr = withMfa(staff($this->owner, 'kwesi', 4, 'branch_manager', ScopeType::Branch, $this->b['G1']->id));
});

it('asks a sister branch, which sends it as a transfer; the list counts what waits on each side', function () {
    $this->actingAs($this->p2Keeper, 'web');
    $form = $this->getJson('/office-api/requests/form')->assertOk()->json();
    expect($form['values']['to_warehouse_id'])->toBe($this->wh['P2']->id)->and(collect($form['choices']['from'])->pluck('id'))->toContain($this->wh['P1']->id);
    expect($this->getJson('/office-api/requests/stock?unit='.($this->unit)($this->para).'&for='.$this->wh['P2']->id.'&from='.$this->wh['P1']->id)->json())
        ->toMatchArray(['mine' => ['qty' => 0, 'unit' => 'PC'], 'theirs' => ['qty' => 50, 'unit' => 'PC']]);
    $this->postJson('/office-api/requests', ['values' => ['to_warehouse_id' => $this->wh['P2']->id, 'from_warehouse_id' => $this->wh['P2']->id,
        'lines' => [['product_uom_id' => ($this->unit)($this->para), 'qty' => '1']]]])->assertStatus(422);
    $id = $this->postJson('/office-api/requests', ['values' => ['to_warehouse_id' => $this->wh['P2']->id, 'from_warehouse_id' => $this->wh['P1']->id,
        'note' => 'Weekend cover', 'lines' => [['product_uom_id' => ($this->unit)($this->para), 'qty' => '10']]]])->assertOk()->json('id');
    $doc = $this->getJson("/office-api/requests/{$id}")->assertOk()->json();
    expect($doc['status'])->toBe('requested')->and($doc['cross'])->toBeFalse()->and($doc['actions']['supply'])->toBeFalse()->and($doc['actions']['cancel'])->toBeTrue()
        ->and($doc['actions']['print'])->toContain('/print/');
    expect($this->getJson('/office-api/requests')->json('waiting'))->toBe(0); // nothing for the asking side to do

    $this->actingAs($this->p1Keeper, 'web');
    expect($this->getJson('/office-api/requests?waiting=1')->json())->toMatchArray(['total' => 1, 'waiting' => 1]);
    $this->getJson("/office-api/requests/{$id}/products?q=wa")->assertOk(); // the supplier may look up its products
    $line = $doc['lines'][0]['id'];
    $doc = $this->postJson("/office-api/requests/{$id}/action", ['action' => 'supply', 'lines' => [$line => ['qty' => '8']]])->assertOk()->json();
    expect($doc['status'])->toBe('supplied')->and($doc['lines'][0]['sent'])->toBe('8')->and($doc['transfer']['status_label'])->toBe('In transit')
        ->and(($this->at)('P1', $this->para))->toBe('42.000');
    expect($this->get('/office-api/export/requests?format=csv')->streamedContent())->toContain(StockRequest::find($id)->number);
});

it('between industries needs both sides to approve, and the supplier names its own product', function () {
    $this->actingAs($this->g1Keeper, 'web');
    $id = $this->postJson('/office-api/requests', ['values' => ['to_warehouse_id' => $this->wh['G1']->id, 'from_warehouse_id' => $this->wh['P1']->id,
        'lines' => [['product_uom_id' => ($this->unit)($this->waterGr), 'qty' => '6']]]])->assertOk()->json('id');
    $doc = $this->getJson("/office-api/requests/{$id}")->json();
    expect($doc['cross'])->toBeTrue()->and($doc['waiting_for'])->toBe('requester')->and($doc['status_label'])->toBe('Awaiting approval (asking side)')
        ->and($doc['actions']['approve'])->toBeFalse();

    $this->actingAs($this->p1Keeper, 'web');
    $this->getJson("/office-api/requests/{$id}/products?q=wa")->assertForbidden(); // not its turn to send yet

    $this->actingAs($this->g1Mgr, 'web');
    $doc = $this->postJson("/office-api/requests/{$id}/action", ['action' => 'approve'])->assertOk()->json();
    expect($doc['waiting_for'])->toBe('supplier');

    $this->actingAs($this->p1Mgr, 'web');
    $doc = $this->postJson("/office-api/requests/{$id}/action", ['action' => 'approve'])->assertOk()->json();
    expect($doc['status'])->toBe('approved')->and($doc['actions']['supply'])->toBeTrue();
    $own = $this->getJson("/office-api/requests/{$id}/products?q=water")->assertOk()->json();
    expect(collect($own)->pluck('id'))->toContain($this->waterPh->id)->not->toContain($this->waterGr->id);
    $line = $doc['lines'][0]['id'];
    $this->postJson("/office-api/requests/{$id}/action", ['action' => 'supply', 'lines' => [$line => ['qty' => '6']]])->assertStatus(422); // no own product named
    $this->postJson("/office-api/requests/{$id}/action", ['action' => 'supply', 'lines' => [$line => ['qty' => '6', 'send_product_id' => $this->waterPh->id]]])
        ->assertOk()->assertJsonPath('status', 'supplied');
    expect(($this->at)('P1', $this->waterPh))->toBe('44.000');
});

it('rejects and cancels with a reason, and keeps requests from a teller', function () {
    $this->actingAs($this->p2Keeper, 'web');
    $ask = fn () => $this->postJson('/office-api/requests', ['values' => ['to_warehouse_id' => $this->wh['P2']->id, 'from_warehouse_id' => $this->wh['P1']->id,
        'lines' => [['product_uom_id' => ($this->unit)($this->para), 'qty' => '2']]]])->assertOk()->json('id');
    $a = $ask();
    $b = $ask();
    $this->postJson("/office-api/requests/{$a}/action", ['action' => 'cancel'])->assertStatus(422);
    $this->postJson("/office-api/requests/{$a}/action", ['action' => 'cancel', 'reason' => 'Found some'])->assertOk()->assertJsonPath('status', 'cancelled')
        ->assertJsonPath('facts.Reason', 'Found some');

    $this->actingAs($this->p1Keeper, 'web');
    $this->postJson("/office-api/requests/{$b}/action", ['action' => 'reject', 'reason' => 'None to spare'])->assertOk()->assertJsonPath('status', 'rejected');
    expect($this->getJson('/office-api/requests?status=rejected')->json('total'))->toBe(1);

    $teller = withMfa(staff($this->owner, 'esi', 2, 'teller', ScopeType::Branch, $this->b['P1']->id));
    $this->actingAs($teller, 'web');
    $this->getJson('/office-api/requests')->assertForbidden();
});
