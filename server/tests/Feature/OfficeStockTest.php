<?php

use App\Domain\Inventory\InventoryService;
use App\Models\StockLevel;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;

// Stock on hand and the stock ledger in the new back office (release 3, part 3): the current screens' records, filters
// and actions, only in the warehouses the person may see.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Warehouse::find(Till::where('branch_id', $this->branches['HQ']->id)->value('warehouse_id'));
    $this->ba = Warehouse::find(Till::where('branch_id', $this->branches['BA']->id)->value('warehouse_id'));
    $this->milo = makeProduct($this->owner, 'MILO-400', 'Milo 400g', retail: '45.00', carton: ['24', '1000.00'], baseBarcode: '6001067000011');
    $this->sugar = makeProduct($this->owner, 'SUGAR-1KG', 'Sugar 1kg', retail: '18.50');
    $inv = app(InventoryService::class);
    $inv->adjust(actorFor($this->owner), $this->hq, $this->milo, $this->milo->units->firstWhere('is_base', true), '40', 'opening', '');
    $inv->adjust(actorFor($this->owner), $this->ba, $this->sugar, $this->sugar->units->firstWhere('is_base', true), '5', 'opening', '');
    $this->owner = withMfa($this->owner->fresh());
});

it('lists stock on hand by warehouse, with counts, search and the level filter', function () {
    $this->actingAs($this->owner, 'web');
    $r = $this->getJson('/office-api/stock')->assertOk()->json();
    expect($r['total'])->toBe(StockLevel::count())->and($r['stocked'])->toBe(2);
    $milo = collect($r['rows'])->firstWhere('sku', 'MILO-400');
    expect($milo)->not->toBeNull();

    $hit = $this->getJson('/office-api/stock?q=6001067000011&level=in')->assertOk()->json();
    expect($hit['total'])->toBe(1)->and($hit['rows'][0]['on_hand'])->toBe('40')->and($hit['rows'][0]['unit'])->toBe('PC');
    expect($this->getJson("/office-api/stock?warehouse={$this->ba->id}&level=in")->json('rows.0.sku'))->toBe('SUGAR-1KG')
        ->and($this->getJson('/office-api/stock?sort=on_hand&dir=desc')->json('rows.0.on_hand'))->toBe('40');
});

it('shows only the warehouses whose stock the person may see', function () {
    $auditor = withMfa(staff($this->owner, 'ama', 1, 'auditor', ScopeType::Branch, $this->branches['BA']->id));
    $this->actingAs($auditor, 'web');
    $r = $this->getJson('/office-api/stock?level=in')->assertOk()->json();
    expect(collect($r['rows'])->pluck('sku')->all())->toBe(['SUGAR-1KG']);
    expect(collect($this->getJson('/office-api/stock/movements')->json('rows'))->pluck('sku')->unique()->values()->all())->toBe(['SUGAR-1KG']);
    expect($this->getJson('/office-api/stock/options')->json('can_adjust'))->toBeFalse();
    $this->postJson('/office-api/stock/adjust', ['type' => 'adjustment', 'warehouse_id' => $this->ba->id, 'product_id' => $this->sugar->id,
        'product_uom_id' => $this->sugar->units->first()->id, 'qty' => '-1', 'reason' => 'damaged'])->assertForbidden();
    $this->get("/office-api/stock/sheet?warehouse={$this->hq->id}&show_qty=1&format=csv&zero=1")->assertForbidden();
});

it('adjusts stock through the ledger, with the reason, and refuses a missing reason', function () {
    $this->actingAs($this->owner, 'web');
    $unit = $this->milo->units->firstWhere('is_base', true);
    expect($this->getJson('/office-api/stock/find?q=milo')->assertOk()->json('results.0.units'))->toHaveCount(2);
    $this->postJson('/office-api/stock/adjust', ['type' => 'adjustment', 'warehouse_id' => $this->hq->id, 'product_id' => $this->milo->id,
        'product_uom_id' => $unit->id, 'qty' => '-3', 'reason' => 'damaged', 'note' => 'Dropped'])->assertOk();
    expect(StockLevel::where('warehouse_id', $this->hq->id)->where('product_id', $this->milo->id)->value('on_hand'))->toBe('37.000');
    $this->postJson('/office-api/stock/adjust', ['type' => 'adjustment', 'warehouse_id' => $this->hq->id, 'product_id' => $this->milo->id,
        'product_uom_id' => $unit->id, 'qty' => '-1'])->assertStatus(422);

    $m = $this->getJson("/office-api/stock/movements?product={$this->milo->id}")->assertOk()->json();
    expect($m['product'])->toBe('MILO 400G')->and($m['total'])->toBe(2)->and($m['rows'][0]['qty'])->toBe('-3')->and($m['rows'][0]['in'])->toBeFalse()
        ->and($m['rows'][0]['type'])->toBe('Adjustment');
    expect($this->getJson('/office-api/stock/movements?types[]=opening')->json('total'))->toBe(2)
        ->and($this->getJson('/office-api/stock/movements/options')->json('types'))->not->toBeEmpty();
});

it('prints the stock sheet, with or without the system quantities', function () {
    $this->actingAs($this->owner, 'web');
    $csv = $this->get("/office-api/stock/sheet?warehouse={$this->hq->id}&show_qty=0&format=csv&zero=0")->assertOk()->streamedContent();
    expect($csv)->toContain('MILO 400G')->toContain('System qty (hidden)')->not->toContain('SUGAR');
});

it('refuses someone who may not see stock', function () {
    $teller = withMfa(staff($this->owner, 'esi', 2, 'teller', ScopeType::Branch, $this->branches['HQ']->id));
    $this->actingAs($teller, 'web');
    $this->getJson('/office-api/stock')->assertForbidden();
    $this->getJson('/office-api/stock/movements')->assertForbidden();
});
