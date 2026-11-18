<?php

use App\Domain\Purchasing\SupplierService;
use App\Models\Account;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

// Purchase orders in the new back office (release 5, part 2): the list, one order with the current page's buttons,
// the draft form with product and service lines, and "Order from a file", through PurchaseOrderService, OrderSender,
// GoodsReceiptService and OrderImporter.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->owner = withMfa($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->b['HQ']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00']);
    $this->ctn = $this->milo->units->firstWhere('is_base', false);
    $this->supplier = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => 'Ernest Chemists', 'phone' => '0244000111', 'email' => 'orders@ernest.test',
        'products' => [['product_uom_id' => $this->ctn->id, 'last_cost' => '240', 'min_order_qty' => '2', 'order_multiple' => '2', 'is_preferred' => true]]]);
    $this->buyer = staff($this->owner, 'ama', 2, 'purchasing_officer');
    $this->gm = staff($this->owner, 'kojo', 5, 'general_manager');
    $this->keeper = staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->b['HQ']->id);
    $this->expense = Account::where('type', 'expense')->where('is_header', false)->whereNull('control')->where('is_active', true)->first();
    $this->values = fn (array $v = []) => ['values' => $v + ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->hq->id, 'expected_on' => now()->addDays(3)->toDateString(),
        'department_id' => '', 'note' => 'Monthly order', 'lines' => [
            ['kind' => 'stock', 'product_uom_id' => $this->ctn->id, 'qty' => '4', 'unit_cost' => ''],
            ['kind' => 'service', 'account_id' => $this->expense->id, 'description' => 'Delivery to the shop', 'uom_code' => 'JOB', 'qty' => '1', 'unit_cost' => '50'],
        ]]];
});

it('makes a draft with product and service lines, lists it, and takes it through approval to the supplier', function () {
    $this->actingAs($this->buyer, 'web');
    $form = $this->getJson('/office-api/purchase-orders/form')->assertOk()->json();
    expect(collect($form['choices']['suppliers'])->pluck('id'))->toContain($this->supplier->id)->and($form['choices']['accounts'])->not->toBeEmpty();
    expect($this->getJson("/office-api/purchase-orders/line-info?supplier={$this->supplier->id}&unit={$this->ctn->id}&warehouse={$this->hq->id}")->json('rule'))
        ->toBe('Supplier sells at least 2, in multiples of 2');

    $this->postJson('/office-api/purchase-orders', ($this->values)(['lines' => [['kind' => 'stock', 'product_uom_id' => $this->ctn->id, 'qty' => '3']]]))
        ->assertStatus(422)->assertJsonPath('detail', fn ($m) => str_contains($m, 'multiples of 2'));
    $id = $this->postJson('/office-api/purchase-orders', ($this->values)())->assertOk()->json('id');
    $po = PurchaseOrder::with('lines')->find($id);
    expect($po->status)->toBe('draft')->and($po->lines)->toHaveCount(2)->and($po->lines[0]->unit_cost_minor)->toBe(24000) // the supplier's last cost
        ->and($po->lines[1]->name)->toBe('Delivery to the shop');

    $edit = $this->getJson("/office-api/purchase-orders/{$id}/form")->assertOk()->json('values');
    expect($edit['lines'][1])->toMatchArray(['kind' => 'service', 'description' => 'Delivery to the shop', 'uom_code' => 'JOB']);
    $this->postJson("/office-api/purchase-orders/{$id}", ['values' => ['note' => 'Changed'] + $edit])->assertOk();

    $list = $this->getJson('/office-api/purchase-orders?q=ernest&status[]=draft')->assertOk()->json();
    expect($list['total'])->toBe(1)->and($list['rows'][0])->toMatchArray(['number' => $po->number, 'status' => 'draft', 'to' => $this->hq->code]);

    expect($this->postJson("/office-api/purchase-orders/{$id}/action", ['action' => 'submit'])->assertOk()->json('order.status'))->toBe('pending_approval');
    expect($this->getJson("/office-api/purchase-orders/{$id}")->json('actions.approve'))->toBeFalse(); // not one's own order
    $this->postJson("/office-api/purchase-orders/{$id}/action", ['action' => 'approve'])->assertStatus(422)->assertJsonPath('error', 'own_document');

    $this->actingAs($this->gm, 'web');
    expect($this->getJson('/office-api/purchase-orders')->json('waiting'))->toBe(1);
    $this->postJson("/office-api/purchase-orders/{$id}/action", ['action' => 'reject'])->assertStatus(422)->assertJsonValidationErrors('reason');
    expect($this->postJson("/office-api/purchase-orders/{$id}/action", ['action' => 'reject', 'reason' => 'Too many'])->json('order.rejected_reason'))->toBe('Too many');
    $this->actingAs($this->buyer, 'web');
    $this->postJson("/office-api/purchase-orders/{$id}/action", ['action' => 'submit'])->assertOk();
    $this->actingAs($this->gm, 'web');
    $approved = $this->postJson("/office-api/purchase-orders/{$id}/action", ['action' => 'approve'])->assertOk()->json('order');
    expect($approved['status'])->toBe('approved')->and($approved['approved_by'])->toBe('Kojo');

    $this->actingAs($this->buyer, 'web');
    $r = $this->postJson("/office-api/purchase-orders/{$id}/action", ['action' => 'send', 'channel' => 'whatsapp_link', 'to' => '0244000111'])->assertOk()->json();
    expect($r['open'])->toContain('wa.me')->and($r['order']['sent']['to'])->not->toBeEmpty()->and($r['order']['actions']['send']['again'])->toBeTrue();

    $csv = $this->get('/office-api/export/purchase-orders?format=csv');
    $csv->assertOk();
    expect($csv->streamedContent())->toContain($po->number);
});

it('receives goods, confirms a service delivered, closes short, orders again and cancels', function () {
    $this->actingAs($this->buyer, 'web');
    $id = $this->postJson('/office-api/purchase-orders', ($this->values)())->json('id');
    $this->postJson("/office-api/purchase-orders/{$id}/action", ['action' => 'submit'])->assertOk();
    $this->actingAs($this->gm, 'web')->postJson("/office-api/purchase-orders/{$id}/action", ['action' => 'approve'])->assertOk();

    $this->actingAs($this->keeper, 'web');
    $show = $this->getJson("/office-api/purchase-orders/{$id}")->assertOk()->json();
    $line = $show['actions']['receive']['lines'][0];
    expect($line['qty'])->toBe('4')->and($show['actions']['confirm_delivered']['lines'])->toHaveCount(1);
    $r = $this->postJson("/office-api/purchase-orders/{$id}/action", ['action' => 'receive', 'supplier_ref' => 'DN-77',
        'lines' => [['purchase_order_line_id' => $line['id'], 'qty' => '2']]])->assertOk()->json();
    expect($r['receipt']['number'])->not->toBeEmpty()->and($r['receipt']['held'])->toBeFalse()->and($r['order']['status'])->toBe('partially_received')
        ->and($r['order']['actions']['receipts'])->toContain('/admin/goods-receipts')
        ->and((string) DB::table('stock_levels')->where('warehouse_id', $this->hq->id)->where('product_id', $this->milo->id)->value('on_hand'))->toBe('48.000');

    $service = $r['order']['actions']['confirm_delivered']['lines'][0];
    $after = $this->postJson("/office-api/purchase-orders/{$id}/action", ['action' => 'confirm_delivered', 'qtys' => [$service['id'] => '1'], 'note' => 'Job card 12'])->assertOk()->json('order');
    expect($after['actions']['confirm_delivered'])->toBeNull();

    $this->actingAs($this->buyer, 'web');
    expect($this->postJson("/office-api/purchase-orders/{$id}/action", ['action' => 'close_short', 'reason' => 'Out of stock at the supplier'])->json('order.status'))->toBe('closed');
    $copy = $this->postJson("/office-api/purchase-orders/{$id}/action", ['action' => 'copy'])->assertOk()->json('copy');
    expect(PurchaseOrder::find($copy['id'])->status)->toBe('draft');
    expect($this->postJson("/office-api/purchase-orders/{$copy['id']}/action", ['action' => 'cancel', 'reason' => 'Not needed'])->json('order.status'))->toBe('cancelled');
});

it('makes a draft from a CSV list, adds a supplier from the form and reads a pack code', function () {
    $this->actingAs($this->buyer, 'web');
    $csv = UploadedFile::fake()->createWithContent('order.csv', "SKU,Qty\nMILO,4\nNOPE,1\n");
    $r = $this->post('/office-api/purchase-orders/import', ['source' => 'sheet', 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->hq->id, 'file' => $csv],
        ['Accept' => 'application/json'])->assertOk()->json();
    expect($r['lines'])->toBe(1)->and($r['skipped'])->toHaveCount(1)->and(PurchaseOrder::find($r['id'])->status)->toBe('draft');
    $this->post('/office-api/purchase-orders/import', ['source' => 'sheet', 'warehouse_id' => $this->hq->id, 'file' => $csv], ['Accept' => 'application/json'])->assertStatus(422);

    $new = $this->postJson('/office-api/purchase-orders/add-supplier', ['name' => 'Kofi Wholesale', 'phone' => '0245550000'])->assertOk()->json();
    expect($new['existed'])->toBeFalse()->and(Supplier::find($new['id'])->name)->toBe('KOFI WHOLESALE');
    expect($this->postJson('/office-api/purchase-orders/add-supplier', ['name' => 'Ernest Chemists'])->json('existed'))->toBeTrue();

    $code = $this->getJson('/office-api/purchase-orders/pack-code?code='.urlencode('(01)09501101020917(17)271231(10)B123'))->assertOk()->json();
    expect($code)->toMatchArray(['batch' => 'B123', 'expires_on' => '2027-12-31']);
});

it('lets a teller see nothing', function () {
    $teller = staff($this->owner, 'efua', 3, 'cashier', ScopeType::Branch, $this->b['HQ']->id);
    $this->actingAs($teller, 'web')->getJson('/office-api/purchase-orders')->assertForbidden();
});
