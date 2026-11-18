<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Purchasing\SmartReorderLevels;
use App\Domain\Purchasing\SupplierService;
use App\Filament\Support\Fields;
use App\Models\PurchaseOrder;
use App\Models\ReorderRule;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Security\ScopeType;

// Reorder levels, What to order and Suggested levels in the new back office (release 5, part 6), through ReorderService
// and SmartReorderLevels.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->owner = withMfa($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->b['HQ']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00']);
    $this->pc = $this->milo->units->firstWhere('is_base', true);
    $this->ctn = $this->milo->units->firstWhere('is_base', false);
    $this->supplier = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => 'Nestle Ghana',
        'products' => [['product_uom_id' => $this->ctn->id, 'last_cost' => '240.00', 'min_order_qty' => '5', 'is_preferred' => true]]]);
    $this->other = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => 'Kofi Wholesale']);
    $this->manager = staff($this->owner, 'kojo', 4, 'warehouse_manager', ScopeType::Branch, $this->b['HQ']->id);
    $this->officer = staff($this->owner, 'ama', 2, 'purchasing_officer');
});

it('sets an own figure in the buying unit, lists it, changes it and puts it back on the default', function () {
    $this->actingAs($this->owner, 'web');
    expect($this->getJson('/office-api/reorder-rules/products?q=milo')->json('results.0.id'))->toBe($this->milo->id)
        ->and($this->getJson("/office-api/reorder-rules/units?product={$this->milo->id}")->json('buying'))->toBe($this->ctn->id);
    $this->postJson('/office-api/reorder-rules', ['warehouse_id' => $this->hq->id, 'product_id' => $this->milo->id, 'product_uom_id' => $this->ctn->id, 'min' => '2', 'max' => '10'])
        ->assertOk()->assertJsonPath('saved', 1);
    $rule = ReorderRule::where('warehouse_id', $this->hq->id)->where('product_id', $this->milo->id)->sole();
    expect((string) $rule->min_qty)->toBe('48.000')->and((string) $rule->max_qty)->toBe('240.000'); // 2 and 10 cartons of 24

    $list = $this->getJson('/office-api/reorder-rules?q=milo')->assertOk()->json();
    expect($list['total'])->toBe(1)->and($list['rows'][0])->toMatchArray(['store' => $this->hq->code, 'source' => 'own', 'editable' => true])
        ->and($list['rows'][0]['min'])->toContain('CTN');
    $form = $this->getJson("/office-api/reorder-rules/{$rule->id}/form")->assertOk()->json();
    expect($form)->toMatchArray(['min' => '48', 'max' => '240', 'product_uom_id' => $this->pc->id]);
    $this->postJson('/office-api/reorder-rules', ['warehouse_id' => $this->hq->id, 'product_id' => $this->milo->id, 'product_uom_id' => $this->pc->id, 'min' => '24', 'max' => '120'])->assertOk();
    expect((string) $rule->fresh()->min_qty)->toBe('24.000');

    $csv = $this->get('/office-api/export/reorder-rules?format=csv');
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('MILO');
    $this->postJson("/office-api/reorder-rules/{$rule->id}/default")->assertOk();
    expect($this->getJson('/office-api/reorder-rules')->json('total'))->toBe(0);
});

it('lists what to order, takes a buyer change, and makes one draft per supplier', function () {
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->hq, $this->milo, $this->pc, '10', 'opening', '', null, '10.00');
    $this->actingAs($this->owner, 'web');
    $this->postJson('/office-api/what-to-order/levels', ['warehouse' => $this->hq->id, 'product' => $this->milo->id, 'min' => '100', 'max' => '300'])->assertOk();

    $list = $this->postJson('/office-api/what-to-order', ['warehouse' => $this->hq->id])->assertOk()->json();
    $row = collect($list['rows'])->firstWhere('product_id', $this->milo->id);
    expect($row)->toMatchArray(['supplier_id' => $this->supplier->id, 'uom_code' => 'CTN', 'changed' => false, 'unit_cost' => 24000, 'qty' => '13']) // 290 PC = 12.1 CTN
        ->and($list['can_levels'])->toBeTrue();
    expect($this->postJson('/office-api/what-to-order', ['warehouse' => $this->hq->id, 'supplier' => 'none'])->json('rows'))->toBe([]);

    $line = $this->getJson("/office-api/what-to-order/line?product={$this->milo->id}")->assertOk()->json();
    expect($line['suppliers'][0]['id'])->toBe($this->supplier->id)->and($line['supplier_units'][$this->supplier->id])->toBe($this->ctn->id);
    $changes = [$this->milo->id => ['supplier_id' => $this->other->id, 'product_uom_id' => $this->ctn->id, 'qty' => '6', 'cost' => '250']];
    $changed = collect($this->postJson('/office-api/what-to-order', ['warehouse' => $this->hq->id, 'changes' => $changes])->json('rows'))->firstWhere('product_id', $this->milo->id);
    expect($changed)->toMatchArray(['changed' => true, 'supplier' => 'KOFI WHOLESALE', 'qty' => '6', 'cost' => '250']);

    $this->postJson('/office-api/what-to-order/drafts', ['warehouse' => $this->hq->id, 'products' => []])->assertStatus(422);
    $orders = $this->postJson('/office-api/what-to-order/drafts', ['warehouse' => $this->hq->id, 'products' => [$this->milo->id], 'changes' => $changes])->assertOk()->json('orders');
    $po = PurchaseOrder::with('lines')->find($orders[0]['id']);
    expect($orders)->toHaveCount(1)->and($po->supplier_id)->toBe($this->other->id)->and($po->status)->toBe('draft')
        ->and((string) $po->lines[0]->qty)->toBe('6.000')->and($po->lines[0]->unit_cost_minor)->toBe(25000);
    expect($this->postJson('/office-api/what-to-order', ['warehouse' => $this->hq->id])->json('rows'))->toBe([]); // covered by the open order
});

it('suggests smart levels and accepts them; a teller sees none of the three', function () {
    $forecast = [['product_id' => $this->milo->id, 'avg_daily' => 30.0, 'lead_days' => 7, 'suggested_min' => 250.4, 'suggested_max' => 670.2]];
    app(SmartReorderLevels::class)->recalculate($this->hq, $forecast);
    $this->actingAs($this->owner, 'web');
    $rows = $this->getJson('/office-api/suggested-levels')->assertOk()->json('rows');
    expect($rows)->toHaveCount(1)->and($rows[0])->toMatchArray(['store' => $this->hq->code, 'now_source' => 'Company default']);
    expect($this->postJson('/office-api/suggested-levels/accept', ['ids' => [$rows[0]['id']]])->assertOk()->json('accepted'))->toBe(1);
    expect(ReorderRule::where('product_id', $this->milo->id)->value('source'))->toBe('smart')
        ->and($this->getJson('/office-api/suggested-levels')->json('rows'))->toBe([]);

    $teller = staff($this->owner, 'efua', 3, 'cashier', ScopeType::Branch, $this->b['HQ']->id);
    $this->actingAs($teller, 'web');
    $this->getJson('/office-api/reorder-rules')->assertForbidden();
    $this->postJson('/office-api/what-to-order', [])->assertForbidden();
    $this->getJson('/office-api/suggested-levels')->assertForbidden();
});

it('shows unit factors stored with six decimals, which failed the Reorder levels unit list before', function () {
    expect(Fields::qty('24.000000'))->toBe('24')->and(Fields::qty('2.500000', 'CTN'))->toBe('2.5 CTN')
        ->and(Fields::qty('0.333333'))->toBe('0.333333')->and(Fields::qty('12.000'))->toBe('12')->and(Fields::qty(null))->toBe('0');
});
