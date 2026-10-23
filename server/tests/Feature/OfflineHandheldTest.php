<?php

use App\Domain\Access\AccessService;
use App\Domain\Devices\DeviceService;
use App\Domain\Inventory\BinService;
use App\Domain\Purchasing\PurchaseOrderService;
use App\Domain\Purchasing\SupplierService;
use App\Models\Bin;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\StockCount;
use App\Models\Supplier;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Offline handhelds (owner request 2026-10-23): warehouse work queued on the handheld, signed, applied once. */
beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00']);
    $this->ctn = $this->milo->units->firstWhere('is_base', false);
    $this->pc = $this->milo->units->firstWhere('is_base', true);
    app(BinService::class)->setUseBins(actorFor($this->owner), $this->hq, true);
    $this->a1 = app(BinService::class)->saveBin(actorFor($this->owner), new Bin, ['warehouse_id' => $this->hq->id, 'code' => 'A-01']);
    $this->recv = BinService::receivingBin($this->hq->fresh());
    $this->keeper = staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->branches['HQ']->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $this->keeper, '482613');

    // A handheld with its own device key, like the till's FakeTill.
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => base_path('tests/fixtures/openssl.cnf')]);
    $spki = trim(preg_replace('/-----[^-]+-----|\s+/', '', openssl_pkey_get_details($key)['key']));
    [$device, $code] = app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $this->branches['HQ']->id, 'name' => 'Scanner 1', 'kind' => 'handheld']);
    $this->hh = new FakeTill($this, $device, $key);
    $this->hh->token = $this->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => $spki])->assertCreated()->json('token');
    app('auth')->forgetGuards();
    $login = $this->withToken($this->hh->token)->postJson('/api/v1/pos/login', ['username' => 'yaw', 'pin' => '482613'])->assertOk()->json();
    $this->hh->grant = $login['offline_grant'];
    $this->session = $login['token'];
    $this->withoutToken();
    app('auth')->forgetGuards();
});

function hhEvent($t, string $type, array $payload, ?string $id = null): array
{
    return ['id' => $id ?? (string) Str::uuid7(), 'type' => $type, 'grant' => $t->hh->grant, 'payload' => $payload];
}

it('gives a handheld an offline sign-in and everything it needs to recognise scans without the server', function () {
    expect($this->hh->grant)->toBeArray()->and($this->hh->grant['username'])->toBe('yaw');
    $cat = $this->withToken($this->session)->getJson("/api/v1/wms/catalog?warehouse_id={$this->hq->id}")->assertOk()->json();
    $unit = collect($cat['units'])->firstWhere('product_uom_id', $this->ctn->id);
    expect($unit['sku'])->toBe('MILO')->and($unit['barcodes'])->not->toBeEmpty()->and(collect($cat['bins'])->pluck('code'))->toContain('A-01');
});

it('receives a delivery and counts stock from the queue, once even when the queue is sent twice', function () {
    $supplier = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['code' => 'NESTLE', 'name' => 'Nestlé Ghana']);
    $po = app(PurchaseOrderService::class)->saveDraft(actorFor($this->owner), new PurchaseOrder, ['supplier_id' => $supplier->id, 'warehouse_id' => $this->hq->id,
        'lines' => [['product_uom_id' => $this->ctn->id, 'qty' => '2', 'unit_cost' => '240']]]);
    app(PurchaseOrderService::class)->submit(actorFor($this->owner), $po);
    app(PurchaseOrderService::class)->approve(actorFor(staff($this->owner, 'gm', 5, 'general_manager')), $po->fresh());
    $line = $po->fresh('lines')->lines->first();

    // Done offline, in this order: receive into the dock, move a carton to A-01, start a count, count, submit.
    $countId = (string) Str::uuid7();
    $events = [
        hhEvent($this, 'wms_receive', ['purchase_order_id' => $po->id, 'supplier_ref' => 'DN-9', 'lines' => [['purchase_order_line_id' => $line->id, 'qty' => '2']]]),
        hhEvent($this, 'wms_putaway', ['warehouse_id' => $this->hq->id, 'product_uom_id' => $this->ctn->id, 'qty' => '1', 'from_bin_id' => $this->recv->id, 'to_bin_id' => $this->a1->id]),
        hhEvent($this, 'wms_count_start', ['id' => $countId, 'warehouse_id' => $this->hq->id, 'bin_id' => $this->a1->id], $countId),
        hhEvent($this, 'wms_count_line', ['count_id' => $countId, 'product_uom_id' => $this->pc->id, 'qty' => '23', 'bin_id' => $this->a1->id]),
        hhEvent($this, 'wms_count_submit', ['count_id' => $countId]),
    ];
    $first = $this->hh->sync($events)->assertOk()->json('results');
    expect(collect($first)->pluck('status')->all())->toBe(['accepted', 'accepted', 'accepted', 'accepted', 'accepted'])
        ->and($first[0]['number'])->toBe(GoodsReceipt::sole()->number);

    // The answer was lost: the handheld sends the same queue again. Nothing happens twice.
    $again = $this->hh->sync($events)->assertOk()->json('results');
    expect(collect($again)->pluck('status')->unique()->all())->toBe(['duplicate'])
        ->and(GoodsReceipt::count())->toBe(1)->and($po->fresh()->status)->toBe('received')
        ->and((string) DB::table('stock_bin_levels')->where('bin_id', $this->a1->id)->value('on_hand'))->toBe('24.000') // one carton of 24, moved once
        ->and(StockCount::find($countId)->status)->toBe('submitted')
        ->and(DB::table('audit_events')->where('action', 'stock_count.counted')->where('actor_name', $this->keeper->name)->where('source', 'handheld')->count())->toBe(1);
});

it('keeps a refused action for review instead of losing it, and only for the person’s own branch', function () {
    $other = Warehouse::where('branch_id', $this->branches['BA']->id)->first();
    $r = $this->hh->sync([hhEvent($this, 'wms_count_start', ['warehouse_id' => $other->id])])->assertOk()->json('results.0');
    expect($r['status'])->toBe('rejected')->and($r['detail'])->toContain('not at this handheld');

    // Warehouse actions come only from a handheld, never from a till.
    $cashier = staff($this->owner, 'esi', 2, 'teller', ScopeType::Branch, $this->branches['HQ']->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $cashier, '559301');
    $till = fakeTill($this, Till::where('branch_id', $this->branches['HQ']->id)->first(), 'esi', '559301');
    $r = $till->sync([['id' => (string) Str::uuid7(), 'type' => 'wms_count_start', 'grant' => $till->grant, 'payload' => ['warehouse_id' => $this->hq->id]]])->assertOk()->json('results.0');
    expect($r['status'])->toBe('rejected')->and($r['detail'])->toContain('Only a warehouse handheld');
});
