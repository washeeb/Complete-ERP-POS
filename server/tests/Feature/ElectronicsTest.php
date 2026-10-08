<?php

use App\Domain\Access\AccessService;
use App\Domain\Catalog\CatalogService;
use App\Domain\Customers\CustomerService;
use App\Domain\Devices\DeviceService;
use App\Domain\Devices\OfflineGrant;
use App\Domain\Electronics\RepairService;
use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\GlCheck;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\SerialRegistry;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\TransferService;
use App\Domain\Pos\ReturnService;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PromotionEngine;
use App\Domain\Purchasing\GoodsReceiptService;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\RepairJobs\RepairJobResource;
use App\Filament\Resources\SerialNumbers\SerialNumberResource;
use App\Models\Account;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ReviewItem;
use App\Models\Sale;
use App\Models\SerialNumber;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\Till;
use App\Models\TradeIn;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Qty;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = $this->branches['HQ'];
    $this->wh = Warehouse::where('branch_id', $this->hq->id)->first();
    enableModule($this->owner, $this->hq, 'electronics');
    $o = actorFor($this->owner);
    $catalog = app(CatalogService::class);
    $this->phone = makeProduct($this->owner, 'TECNO', 'Tecno Spark 20', retail: '1500.00');
    $catalog->saveProduct($o, $this->phone, ['track_serials' => true, 'warranty_months' => 12]);
    $this->phone = $this->phone->fresh();
    $this->used = makeProduct($this->owner, 'USED-PH', 'Used phone', retail: '800.00');
    $catalog->saveProduct($o, $this->used, ['track_serials' => true]);
    $this->used = $this->used->fresh();
    $this->screen = makeProduct($this->owner, 'SCR', 'Replacement screen', retail: '200.00');
    $this->labour = makeProduct($this->owner, 'LAB', 'Repair labour (hour)', retail: '100.00');
    $catalog->saveProduct($o, $this->labour, ['track_stock' => false]);
    $this->labour = $this->labour->fresh();
    app(InventoryService::class)->adjust($o, $this->wh, $this->screen, $this->screen->units->first(), '10', 'opening', '', null, '120.00');
    $this->supplier = app(SupplierService::class)->save($o, new Supplier, ['code' => 'TEC', 'name' => 'Tecno Ghana']);
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $this->till = Till::where('branch_id', $this->hq->id)->first();
    [$this->device] = app(DeviceService::class)->register($o, ['branch_id' => $this->hq->id, 'till_id' => $this->till->id, 'name' => 'T1', 'kind' => 'pos']);
    $this->device->forceFill(['status' => 'active'])->save();
    $this->cashier = staff($this->owner, 'efua', 2, 'cashier', ScopeType::Till, $this->till->id);
    $this->supervisor = staff($this->owner, 'kojo', 3, 'supervisor', ScopeType::Branch, $this->hq->id);
    app(AccessService::class)->setPin($o, $this->supervisor, '771204');
    $this->tech = staff($this->owner, 'yaw', 2, 'technician', ScopeType::Branch, $this->hq->id);
    $this->shift = app(ShiftService::class)->open($this->device, $this->cashier, 0);
    $this->seq = 0;
    $this->receive = fn (array $serials, ?Product $p = null) => app(GoodsReceiptService::class)->receive($o, null, ['warehouse_id' => $this->wh->id,
        'supplier_id' => $this->supplier->id, 'lines' => [['product_uom_id' => ($p ?? $this->phone)->units->first()->id, 'qty' => (string) count($serials), 'unit_cost' => '1000.00',
            'serials' => $serials]]]);
});

/** A till sale: items [product, qty, extra line fields]; payments default to cash for the rest. */
function elecSale($t, array $items, array $extra = [], array $payments = [], bool $offline = false): Sale
{
    $lines = [];
    $engine = [];
    foreach ($items as $i => $it) {
        [$p, $qty] = $it;
        $unit = $p->units->first();
        $price = app(PriceResolver::class)->resolve($t->retail, $p->id, $unit->id, Qty::toMilli($qty), now())->amount_minor;
        $engine[] = ['key' => (string) $i, 'product_id' => $p->id, 'category_id' => null, 'qty_milli' => Qty::toMilli($qty), 'unit_price_minor' => $price,
            'tax' => $p->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all(), 'manual' => null];
        $lines[] = ['product_id' => $p->id, 'product_uom_id' => $unit->id, 'qty' => $qty, 'unit_price_minor' => $price] + ($it[2] ?? []);
    }
    $calc = PromotionEngine::compute(['lines' => $engine, 'inclusive' => true, 'coupon' => null, 'sale_discount_bp' => 0, 'promotions' => []]);
    foreach ($calc['lines'] as $i => $r) {
        $lines[$i]['line_total_minor'] = $r['total'];
    }
    $rest = $calc['total'] - array_sum(array_column($payments, 'amount_minor'));
    if ($rest > 0) {
        $payments[] = ['method' => 'cash', 'amount_minor' => $rest, 'tendered_minor' => $rest];
    }
    $payload = ['id' => (string) Str::uuid7(), 'device_seq' => ++$t->seq, 'occurred_at' => now()->toIso8601String(), 'price_list_id' => $t->retail->id,
        'shift_id' => $t->shift->id, 'lines' => $lines, 'totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $calc['total']],
        'payments' => $payments] + $extra;
    if ($offline) {
        $grant = app(OfflineGrant::class)->issue($t->cashier, $t->device, '482613');

        return app(SaleService::class)->post($t->device, null, $payload, app(OfflineGrant::class)->verify($grant))['sale'];
    }

    return app(SaleService::class)->post($t->device, $t->cashier, $payload)['sale'];
}

it('receives, sells, returns and transfers phones by IMEI, with the warranty from the sale date', function () {
    expect(fn () => ($this->receive)([]))->toThrow(DomainException::class)
        ->and(fn () => app(GoodsReceiptService::class)->receive(actorFor($this->owner), null, ['warehouse_id' => $this->wh->id, 'supplier_id' => $this->supplier->id,
            'lines' => [['product_uom_id' => $this->phone->units->first()->id, 'qty' => '2', 'unit_cost' => '1000.00', 'serials' => ['111']]]]))->toThrow(DomainException::class, 'serial number of each unit');
    ($this->receive)(['3569-0101 0000001', '356901010000002', '356901010000003']);
    expect(SerialNumber::where('status', 'in_stock')->pluck('serial')->sort()->values()->all())->toBe(['356901010000001', '356901010000002', '356901010000003'])
        ->and(fn () => ($this->receive)(['356901010000001']))->toThrow(DomainException::class, 'already in stock');

    $ama = app(CustomerService::class)->save(actorFor($this->owner), new Customer, ['name' => 'Ama Owusu', 'phone' => '0241112222']);
    expect(fn () => elecSale($this, [[$this->phone, '1']]))->toThrow(DomainException::class, 'serial number')
        ->and(fn () => elecSale($this, [[$this->phone, '1', ['serials' => ['999999999999999']]]]))->toThrow(DomainException::class, 'not in stock');
    $sale = elecSale($this, [[$this->phone, '1', ['serials' => ['356901010000001']]]], ['customer_id' => $ama->id]);
    $sold = SerialNumber::where('serial', '356901010000001')->sole();
    expect($sold->status)->toBe('sold')->and($sold->customer_id)->toBe($ama->id)->and($sold->sale_id)->toBe($sale->id)
        ->and($sold->warranty_until->toDateString())->toBe(now()->addMonths(12)->toDateString())->and($sale->lines->first()->serials)->toBe(['356901010000001']);

    // Returned: back in stock, by the number sold.
    $ret = app(ReturnService::class);
    $line = collect($ret->returnable($sale->fresh('lines', 'payments'))['lines'])->first();
    $ret->post($this->device, $this->cashier, ['id' => (string) Str::uuid7(), 'device_seq' => ++$this->seq, 'occurred_at' => now()->toIso8601String(), 'shift_id' => $this->shift->id,
        'original_sale_id' => $sale->id, 'reason' => 'Faulty', 'lines' => [['original_line_id' => $line['id'], 'qty' => '1']], 'refunds' => [['method' => 'cash', 'amount_minor' => 150000]]]);
    expect($sold->fresh()->status)->toBe('in_stock');

    // Transferred: named units go in transit and arrive at the other branch.
    $ba = Warehouse::where('branch_id', $this->branches['BA']->id)->first();
    $tr = app(TransferService::class);
    $t = $tr->saveDraft(actorFor($this->owner), new StockTransfer, ['from_warehouse_id' => $this->wh->id, 'to_warehouse_id' => $ba->id,
        'lines' => [['product_uom_id' => $this->phone->units->first()->id, 'qty' => '2', 'serials' => ['356901010000002', '356901010000003']]]]);
    $tr->dispatch(actorFor($this->owner), $t);
    expect(SerialNumber::where('status', 'in_transit')->count())->toBe(2);
    $tr->receive(actorFor($this->owner), $t->fresh());
    expect(SerialNumber::where('warehouse_id', $ba->id)->where('status', 'in_stock')->count())->toBe(2)
        ->and(SerialRegistry::check($this->tenant->id))->toBe([])->and(app(StockLedger::class)->check())->toBe([]);

    // History of one unit: every movement that moved it.
    expect(DB::table('stock_movement_serials')->where('serial_number_id', $sold->id)->count())->toBe(3); // received, sold, returned
});

it('keeps an offline sale without its IMEI and asks for the register to be put right', function () {
    ($this->receive)(['356901010000009']);
    elecSale($this, [[$this->phone, '1']], offline: true);
    expect(ReviewItem::where('type', 'serial_problem')->count())->toBe(1)->and(SerialRegistry::check($this->tenant->id))->not->toBe([]);
});

it('registers serial numbers for phones already on hand when tracking is switched on', function () {
    $old = makeProduct($this->owner, 'OLD', 'Itel A70', retail: '700.00');
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->wh, $old, $old->units->first(), '2', 'opening', '', null, '500.00');
    app(CatalogService::class)->saveProduct(actorFor($this->owner), $old, ['track_serials' => true]);
    $old = $old->fresh();
    $inv = app(InventoryService::class);
    expect(fn () => $inv->registerSerials(actorFor($this->owner), $this->wh, $old, 'A1 A2 A3'))->toThrow(DomainException::class, '2 on hand');
    expect($inv->registerSerials(actorFor($this->owner), $this->wh, $old, 'A1, A2'))->toBe(2)->and(SerialRegistry::check($this->tenant->id))->toBe([]);
});

it('takes a used phone in trade with a supervisor and puts it into stock at the value given', function () {
    ($this->receive)(['356901010000001']);
    $trade = ['trade_ins' => [['product_id' => $this->used->id, 'serial' => '352000111222333', 'condition' => 'Screen scratched', 'value_minor' => 50000]]];
    $pay = [['method' => 'trade_in', 'amount_minor' => 50000]];
    $item = [[$this->phone, '1', ['serials' => ['356901010000001']]]];
    expect(fn () => elecSale($this, $item, $trade, $pay))->toThrow(DomainException::class, 'supervisor must approve')
        ->and(fn () => elecSale($this, $item, $trade + ['trade_in_approval' => ['username' => 'kojo', 'pin' => '771204']], [['method' => 'trade_in', 'amount_minor' => 40000]]))->toThrow(DomainException::class, 'must equal');
    $sale = elecSale($this, $item, $trade + ['trade_in_approval' => ['username' => 'kojo', 'pin' => '771204']], $pay);
    expect($sale->paid_minor)->toBe(150000)->and(TradeIn::sole()->approved_by_name)->toBe('Kojo')
        ->and(SerialNumber::where('serial', '352000111222333')->sole()->status)->toBe('in_stock')
        ->and((string) DB::table('stock_levels')->where('product_id', $this->used->id)->value('avg_cost_minor'))->toStartWith('50000');

    app(AutoPoster::class)->run($this->tenant->id);
    $bal = fn (string $k) => (int) DB::table('journal_lines')->where('account_id', Account::where('system_key', $k)->value('id'))->sum('amount_minor');
    expect($bal('trade_in_clearing'))->toBe(0)->and(app(GlCheck::class)->run($this->tenant->id)['ok'])->toBeTrue();
});

it('runs repair jobs: in warranty for free with parts written off, otherwise paid at the till', function () {
    ($this->receive)(['356901010000001', '356901010000002']);
    elecSale($this, [[$this->phone, '1', ['serials' => ['356901010000001']]]]);
    $svc = app(RepairService::class);
    $tech = actorFor($this->tech);

    // Sold by us four months ago-ish: under warranty → no charge, the screen is written off to the repair.
    $job = $svc->open($tech, ['branch_id' => $this->hq->id, 'customer_name' => 'Kofi', 'phone' => '0201234567', 'serial' => '3569 0101 0000001', 'fault' => 'Screen cracked by itself']);
    expect($job->number)->toStartWith('JOB-')->and($job->in_warranty)->toBeTrue()->and($job->device)->toBe('Tecno Spark 20');
    $job = $svc->setLines($tech, $job, [['product_uom_id' => $this->screen->units->first()->id, 'qty' => '1']]);
    expect($job->lines->sole()->unit_price_minor)->toBe(0)
        ->and(fn () => $svc->setStatus($tech, $job, 'collected'))->toThrow(DomainException::class);
    $svc->setStatus($tech, $job, 'repairing', 'Screen replaced');
    $svc->setStatus($tech, $job->fresh(), 'ready');
    $job = $svc->handOver($tech, $job->fresh(), $this->wh);
    expect($job->status)->toBe('collected')->and((string) DB::table('stock_levels')->where('product_id', $this->screen->id)->value('on_hand'))->toBe('9.000');

    // Out of warranty (not sold by us): priced parts and labour, collected by paying at the till.
    $job = $svc->open($tech, ['branch_id' => $this->hq->id, 'customer_name' => 'Esi', 'device' => 'Samsung A12', 'fault' => 'Will not charge']);
    $job = $svc->setLines($tech, $job, [['product_uom_id' => $this->screen->units->first()->id, 'qty' => '1'], ['product_uom_id' => $this->labour->units->first()->id, 'qty' => '1']]);
    expect($job->in_warranty)->toBeFalse()->and($job->lines->sum('unit_price_minor'))->toBe(30000);
    $svc->setStatus($tech, $job, 'ready');
    expect(fn () => $svc->handOver($tech, $job->fresh(), $this->wh))->toThrow(DomainException::class, 'take payment');
    $sale = elecSale($this, [[$this->screen, '1', ['repair_job_id' => $job->id]], [$this->labour, '1', ['repair_job_id' => $job->id]]]);
    expect($job->fresh()->status)->toBe('collected')->and($job->fresh()->sale_id)->toBe($sale->id)
        ->and((string) DB::table('stock_levels')->where('product_id', $this->screen->id)->value('on_hand'))->toBe('8.000');

    // Back to the supplier (RMA).
    $rma = $svc->open($tech, ['branch_id' => $this->hq->id, 'customer_name' => 'Yaa', 'serial' => '356901010000001', 'fault' => 'Dead']);
    $rma = $svc->setStatus($tech, $rma, 'at_supplier', 'Board fault', $this->supplier->id, 'RMA-778');
    expect($rma->rma_reference)->toBe('RMA-778')->and(count($rma->log))->toBe(2);
    expect(fn () => $svc->open(actorFor($this->cashier), ['branch_id' => $this->hq->id, 'customer_name' => 'X', 'device' => 'Y', 'fault' => 'Z']))
        ->toThrow(AccessDenied::class);
});

it('shows the electronics screens', function () {
    ($this->receive)(['356901010000001']);
    $job = app(RepairService::class)->open(actorFor($this->tech), ['branch_id' => $this->hq->id, 'customer_name' => 'Kofi', 'serial' => '356901010000001', 'fault' => 'No sound']);
    $this->actingAs(withMfa($this->owner));
    $serial = SerialNumber::sole();
    foreach ([SerialNumberResource::getUrl('index'), SerialNumberResource::getUrl('view', ['record' => $serial]), RepairJobResource::getUrl('index'),
        RepairJobResource::getUrl('view', ['record' => $job]), ProductResource::getUrl('edit', ['record' => $this->phone])] as $url) {
        $this->get($url)->assertOk();
    }
    $this->get(SerialNumberResource::getUrl('view', ['record' => $serial]))->assertSee('receipt');
    $this->get(RepairJobResource::getUrl('view', ['record' => $job]))->assertSee('No sound')->assertSee('Received');
});
