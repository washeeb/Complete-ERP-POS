<?php

use App\Domain\Purchasing\GoodsReceiptService;
use App\Domain\Purchasing\PurchaseOrderService;
use App\Domain\Purchasing\SupplierService;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

// Goods received notes in the new back office (release 5, part 3): the list, one note, and accept / refuse a held
// delivery and reverse a posted one, through GoodsReceiptService.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->owner = withMfa($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->b['HQ']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00']);
    $ctn = $this->milo->units->firstWhere('is_base', false);
    $supplier = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => 'Ernest Chemists']);
    $this->buyer = staff($this->owner, 'ama', 2, 'purchasing_officer');
    $this->gm = staff($this->owner, 'kojo', 5, 'general_manager');
    $this->keeper = staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->b['HQ']->id);
    $svc = app(PurchaseOrderService::class);
    $po = $svc->saveDraft(actorFor($this->buyer), new PurchaseOrder, ['supplier_id' => $supplier->id, 'warehouse_id' => $this->hq->id,
        'lines' => [['product_uom_id' => $ctn->id, 'qty' => '4', 'unit_cost' => '240']]]);
    $svc->submit(actorFor($this->buyer), $po);
    $this->po = $svc->approve(actorFor($this->gm), $po->fresh());
    $this->receive = fn (string $qty, string $ref = 'DN-1') => app(GoodsReceiptService::class)->receive(actorFor($this->keeper), $this->po->fresh(),
        ['supplier_ref' => $ref, 'lines' => [['purchase_order_line_id' => $this->po->lines()->first()->id, 'qty' => $qty]]]);
    $this->onHand = fn () => (string) DB::table('stock_levels')->where('warehouse_id', $this->hq->id)->where('product_id', $this->milo->id)->value('on_hand');
});

it('lists the notes, opens one, exports and reverses a mistake', function () {
    $grn = ($this->receive)('2', 'DN-77');
    $this->actingAs(withMfa($this->keeper), 'web');
    $list = $this->getJson('/office-api/goods-receipts?q=DN-77')->assertOk()->json();
    expect($list['total'])->toBe(1)->and($list['rows'][0])->toMatchArray(['number' => $grn->number, 'order' => $this->po->number, 'status' => 'posted', 'value' => 48000]);
    expect($this->getJson("/office-api/goods-receipts?q={$this->po->number}")->json('total'))->toBe(1);

    $show = $this->getJson("/office-api/goods-receipts/{$grn->id}")->assertOk()->json();
    expect($show['order']['open'])->toBe("/office/purchase-orders/{$this->po->id}")->and($show['lines'][0])->toMatchArray(['sku' => 'MILO', 'cost' => 24000])
        ->and($show['actions']['reverse'])->toBeFalse() // a storekeeper receives; a manager reverses
        ->and($show['product_ids'])->toBe([$this->milo->id]);
    $this->postJson("/office-api/goods-receipts/{$grn->id}/action", ['action' => 'reverse', 'reason' => 'Wrong order'])->assertForbidden();

    $csv = $this->get('/office-api/export/goods-receipts?format=csv');
    $csv->assertOk();
    expect($csv->streamedContent())->toContain($grn->number);

    $this->actingAs(withMfa($this->gm), 'web');
    $this->postJson("/office-api/goods-receipts/{$grn->id}/action", ['action' => 'reverse'])->assertStatus(422)->assertJsonValidationErrors('reason');
    $after = $this->postJson("/office-api/goods-receipts/{$grn->id}/action", ['action' => 'reverse', 'reason' => 'Wrong order'])->assertOk()->json();
    expect($after['status'])->toBe('reversed')->and($after['reversal']['reason'])->toBe('Wrong order')->and(($this->onHand)())->toBe('0.000');
});

it('holds a delivery above the order for a manager, who accepts or refuses it', function () {
    app(Settings::class)->set(actorFor($this->owner), 'purchasing.over_receipt_pct', 0);
    $held = ($this->receive)('6');
    expect($held->status)->toBe('held');
    $this->actingAs(withMfa($this->gm), 'web');
    expect($this->getJson('/office-api/goods-receipts')->json('held'))->toBe(1)
        ->and($this->getJson('/office-api/goods-receipts?status=held')->json('rows.0.number'))->toBe($held->number);
    $refused = $this->postJson("/office-api/goods-receipts/{$held->id}/action", ['action' => 'refuse', 'reason' => 'Too many'])->assertOk()->json();
    expect($refused['status'])->toBe('reversed')->and(($this->onHand)())->toBe('0.000');

    $again = ($this->receive)('6');
    $accepted = $this->postJson("/office-api/goods-receipts/{$again->id}/action", ['action' => 'accept'])->assertOk()->json();
    expect($accepted['status'])->toBe('posted')->and($accepted['approved_by'])->toBe('Kojo')->and(($this->onHand)())->toBe('144.000');
});

it('lets a teller see nothing', function () {
    $teller = staff($this->owner, 'efua', 3, 'cashier', ScopeType::Branch, $this->b['HQ']->id);
    $this->actingAs(withMfa($teller), 'web')->getJson('/office-api/goods-receipts')->assertForbidden();
    expect(GoodsReceipt::count())->toBe(0);
});
