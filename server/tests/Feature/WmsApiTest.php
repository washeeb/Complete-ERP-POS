<?php

use App\Domain\Access\AccessService;
use App\Domain\Devices\DeviceService;
use App\Domain\Inventory\BinService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\TransferService;
use App\Domain\Purchasing\PurchaseOrderService;
use App\Domain\Purchasing\SupplierService;
use App\Models\Bin;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\StockCount;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00']);
    $this->ctn = $this->milo->units->firstWhere('is_base', false);
    app(BinService::class)->setUseBins(actorFor($this->owner), $this->hq, true);
    $this->a1 = app(BinService::class)->saveBin(actorFor($this->owner), new Bin, ['warehouse_id' => $this->hq->id, 'code' => 'A-01']);
    $this->recv = BinService::receivingBin($this->hq->fresh());

    [, $code] = app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $this->branches['HQ']->id, 'name' => 'Scanner 1', 'kind' => 'handheld']);
    $enrol = $this->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->assertCreated();
    expect($enrol->json('kind'))->toBe('handheld');
    $this->deviceToken = $enrol->json('token');
    $this->keeper = staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->branches['HQ']->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $this->keeper, '482613');
    app('auth')->forgetGuards();
    $this->token = $this->withToken($this->deviceToken)->postJson('/api/v1/pos/login', ['username' => 'yaw', 'pin' => '482613'])->assertOk()->json('token');
    app('auth')->forgetGuards();
});

function wms($t, string $method, string $uri, array $body = [])
{
    app('auth')->forgetGuards();

    return $t->withToken($t->token)->json($method, "/api/v1/wms/{$uri}", $body);
}

it('signs a storekeeper in on a handheld (no till access needed) and refuses people without warehouse work', function () {
    wms($this, 'GET', 'bootstrap')->assertOk()->assertJsonPath('warehouses.0.use_bins', true)
        ->assertJsonPath('warehouses.0.can', ['stock.receive', 'stock.transfer', 'stock.count'])->assertJsonPath('warehouses.0.bins.0.code', 'A-01');

    $cashier = staff($this->owner, 'esi', 2, 'cashier', ScopeType::Branch, $this->branches['HQ']->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $cashier, '559301');
    app('auth')->forgetGuards();
    $this->withToken($this->deviceToken)->postJson('/api/v1/pos/login', ['username' => 'esi', 'pin' => '559301'])->assertForbidden();

    app('auth')->forgetGuards();
    $this->withToken($this->token)->getJson('/api/v1/pos/bootstrap')->assertUnauthorized(); // a handheld session is not a till session
});

it('receives a purchase order by scanning, once even if the handheld sends twice, then puts it away', function () {
    $supplier = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['code' => 'NESTLE', 'name' => 'Nestlé Ghana']);
    $po = app(PurchaseOrderService::class)->saveDraft(actorFor($this->owner), new PurchaseOrder, ['supplier_id' => $supplier->id, 'warehouse_id' => $this->hq->id,
        'lines' => [['product_uom_id' => $this->ctn->id, 'qty' => '2', 'unit_cost' => '240']]]);
    app(PurchaseOrderService::class)->submit(actorFor($this->owner), $po);
    app(PurchaseOrderService::class)->approve(actorFor(staff($this->owner, 'gm', 5, 'general_manager')), $po->fresh());

    $list = wms($this, 'GET', "purchase-orders?warehouse_id={$this->hq->id}")->assertOk()->json();
    expect($list[0]['number'])->toBe($po->number)->and($list[0]['lines'][0]['barcodes'])->not->toBeEmpty();
    $scan = wms($this, 'GET', 'lookup?code='.$list[0]['lines'][0]['barcodes'][0])->assertOk();
    expect($scan->json('unit.product_uom_id'))->toBe($this->ctn->id);
    wms($this, 'GET', "lookup?code=a-01&warehouse_id={$this->hq->id}")->assertOk()->assertJsonPath('bin.id', $this->a1->id);

    $body = ['purchase_order_id' => $po->id, 'client_id' => (string) Str::uuid7(), 'supplier_ref' => 'DN-77',
        'lines' => [['purchase_order_line_id' => $list[0]['lines'][0]['id'], 'qty' => '2']]];
    wms($this, 'POST', 'receipts', $body)->assertCreated()->assertJsonPath('status', 'posted');
    wms($this, 'POST', 'receipts', $body)->assertCreated(); // the answer was lost; sent again
    expect(GoodsReceipt::count())->toBe(1)->and($po->fresh()->status)->toBe('received');

    wms($this, 'GET', "bin-stock?bin_id={$this->recv->id}")->assertOk()->assertJsonPath('0.on_hand', '48.000');
    wms($this, 'POST', 'putaway', ['warehouse_id' => $this->hq->id, 'product_uom_id' => $this->ctn->id, 'qty' => '1',
        'from_bin_id' => $this->recv->id, 'to_bin_id' => $this->a1->id])->assertOk();
    expect((string) DB::table('stock_bin_levels')->where('bin_id', $this->a1->id)->value('on_hand'))->toBe('24.000');
});

it('receives a transfer and counts blind from the handheld', function () {
    $ba = Warehouse::where('branch_id', $this->branches['BA']->id)->first();
    app(InventoryService::class)->adjust(actorFor($this->owner), $ba, $this->milo, $this->ctn, '1', 'opening', '');
    $t = app(TransferService::class)->saveDraft(actorFor($this->owner), new StockTransfer, ['from_warehouse_id' => $ba->id, 'to_warehouse_id' => $this->hq->id,
        'lines' => [['product_uom_id' => $this->ctn->id, 'qty' => '1']]]);
    app(TransferService::class)->dispatch(actorFor($this->owner), $t);

    $incoming = wms($this, 'GET', "transfers?warehouse_id={$this->hq->id}")->assertOk()->json();
    wms($this, 'POST', "transfers/{$incoming[0]['id']}/receive", ['lines' => [$incoming[0]['lines'][0]['id'] => '1'], 'to_bin_id' => $this->a1->id])
        ->assertOk()->assertJsonPath('status', 'received')->assertJsonPath('has_discrepancy', false);

    $count = wms($this, 'POST', 'counts', ['warehouse_id' => $this->hq->id, 'bin_id' => $this->a1->id])->assertCreated()->json();
    $r = wms($this, 'POST', "counts/{$count['id']}/lines", ['product_uom_id' => $this->milo->units->firstWhere('is_base', true)->id, 'qty' => '23'])->assertOk();
    expect($r->json())->not->toHaveKey('expected_qty');
    wms($this, 'POST', "counts/{$count['id']}/submit")->assertOk()->assertJsonPath('status', 'submitted');
    expect(StockCount::find($count['id'])->lines()->first()->counted_qty)->toBe('23.000');
});

it('keeps a handheld to its own branch', function () {
    $ba = Warehouse::where('branch_id', $this->branches['BA']->id)->first();
    wms($this, 'GET', "purchase-orders?warehouse_id={$ba->id}")->assertNotFound();
});
