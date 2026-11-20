<?php

use App\Domain\Purchasing\GoodsReceiptService;
use App\Domain\Purchasing\PurchaseOrderService;
use App\Domain\Purchasing\SupplierService;
use App\Models\Price;
use App\Models\PriceList;
use App\Models\PriceSuggestion;
use App\Models\PricingRule;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Security\ScopeType;

// Pricing rules and Prices to review in the new back office (catalog and pricing setup, part 8), through PricingRules.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    withMfa($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '12.00', carton: ['24', '330.00']);
    $this->pc = $this->milo->units->firstWhere('is_base', true);
    $this->ctn = $this->milo->units->firstWhere('is_base', false);
    $this->supplier = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => 'Nestlé Ghana']);
    $this->buyer = staff($this->owner, 'ama', 2, 'purchasing_officer');
    $this->manager = staff($this->owner, 'kojo', 4, 'warehouse_manager', ScopeType::Branch, $this->branches['HQ']->id);
    $this->keeper = staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->branches['HQ']->id);
});

function officePricingDeliver($t, string $cartonCost): void
{
    $po = app(PurchaseOrderService::class)->saveDraft(actorFor($t->buyer), new PurchaseOrder, ['supplier_id' => $t->supplier->id, 'warehouse_id' => $t->hq->id,
        'lines' => [['product_uom_id' => $t->ctn->id, 'qty' => '10', 'unit_cost' => $cartonCost]]]);
    app(PurchaseOrderService::class)->submit(actorFor($t->buyer), $po);
    $po = app(PurchaseOrderService::class)->approve(actorFor($t->manager), $po->fresh());
    app(GoodsReceiptService::class)->receive(actorFor($t->keeper), $po, ['lines' => [['purchase_order_line_id' => $po->lines[0]->id, 'qty' => '10']]]);
}

it('adds and changes a pricing rule, refuses a second one for the same place, and applies it to all products', function () {
    $this->actingAs($this->owner, 'web');
    $o = $this->getJson('/office-api/pricing-rules/options')->assertOk()->json();
    expect($o['default_list'])->toBe($this->retail->id)->and(collect($o['methods'])->pluck('id')->all())->toContain('multiply');
    expect(collect($this->getJson('/office-api/pricing-rules/products?q=milo')->json('rows'))->pluck('id')->all())->toContain($this->milo->id);

    $base = ['scope' => 'company', 'price_list_id' => $this->retail->id, 'method' => 'multiply', 'tax_mode' => 'inclusive', 'round_minor' => 10, 'round_mode' => 'nearest',
        'on_change' => 'rise_auto', 'is_active' => true];
    $this->postJson('/office-api/pricing-rules', ['value' => '0.5'] + $base)->assertStatus(422); // at least 1
    $id = $this->postJson('/office-api/pricing-rules', ['value' => '1.40'] + $base)->assertOk()->json('id');
    $this->postJson('/office-api/pricing-rules', ['value' => '1.50'] + $base)->assertStatus(422); // one per place and list
    $this->postJson("/office-api/pricing-rules/{$id}", ['value' => '1.50'] + $base)->assertOk();
    expect(PricingRule::find($id)->value_bp)->toBe(15000);
    $row = collect($this->getJson('/office-api/pricing-rules')->assertOk()->json('rows'))->firstWhere('id', $id);
    expect($row)->toMatchArray(['applies_to' => 'Whole company', 'active' => true, 'can_edit' => true])->and($row['values']['value'])->toBe('1.5');

    officePricingDeliver($this, '240.00'); // 10.00 a piece
    $this->postJson('/office-api/pricing-rules/apply-all')->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'price(s) set'));
    expect(Price::where('price_list_id', $this->retail->id)->where('product_uom_id', $this->pc->id)->whereNull('starts_at')->value('amount_minor'))->toBe(1500);
});

it('lists prices waiting for review, approves one and keeps the other, and keeps others out', function () {
    $this->actingAs($this->owner, 'web');
    $this->postJson('/office-api/pricing-rules', ['scope' => 'company', 'price_list_id' => $this->retail->id, 'method' => 'multiply', 'value' => '1.40'])->assertOk();
    officePricingDeliver($this, '240.00');
    officePricingDeliver($this, '192.00'); // a drop: waits for a manager

    $l = $this->getJson('/office-api/prices-to-review?q=milo')->assertOk()->json();
    expect($l['total'])->toBe(2)->and(collect($l['rows'])->pluck('new')->sort()->values()->all())->toBe([1120, 26880]);
    [$piece, $carton] = collect($l['rows'])->sortBy('new')->pluck('id')->all();
    $this->postJson('/office-api/prices-to-review/approve', ['ids' => [$piece]])->assertOk();
    $this->postJson('/office-api/prices-to-review/keep', ['ids' => [$carton]])->assertOk();
    expect(PriceSuggestion::find($piece)->status)->toBe('applied')->and(PriceSuggestion::find($carton)->status)->toBe('dismissed')
        ->and($this->getJson('/office-api/prices-to-review')->json('total'))->toBe(0);

    $this->actingAs($this->keeper, 'web');
    $this->getJson('/office-api/pricing-rules')->assertForbidden();
    $this->getJson('/office-api/prices-to-review')->assertForbidden();
});
