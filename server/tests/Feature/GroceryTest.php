<?php

use App\Domain\Access\AccessService;
use App\Domain\Catalog\CatalogService;
use App\Domain\Devices\DeviceService;
use App\Domain\Devices\OfflineGrant;
use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\GlCheck;
use App\Domain\Grocery\EslRelay;
use App\Domain\Grocery\MarkdownService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\TransferService;
use App\Domain\Modules\ModuleService;
use App\Domain\Pos\ReturnService;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PromotionEngine;
use App\Domain\Pricing\PromotionService;
use App\Domain\Purchasing\GoodsReceiptService;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ShrinkReport;
use App\Models\Markdown;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\ReviewItem;
use App\Models\Sale;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Ean13;
use App\Support\Qty;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = $this->branches['HQ'];
    $this->wh = Warehouse::where('branch_id', $this->hq->id)->first();
    app(ModuleService::class)->setStatus(actorFor($this->owner), $this->hq, 'grocery', 'enabled');
    $this->yoghurt = makeProduct($this->owner, 'YOG', 'Fan Yogo 250ml', retail: '10.00');
    app(CatalogService::class)->saveProduct(actorFor($this->owner), $this->yoghurt, ['track_batches' => true]);
    $this->yoghurt = $this->yoghurt->fresh();
    $this->unit = $this->yoghurt->units->first();
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $this->till = Till::where('branch_id', $this->hq->id)->first();
    [$this->device] = app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $this->hq->id, 'till_id' => $this->till->id, 'name' => 'T1', 'kind' => 'pos']);
    $this->device->forceFill(['status' => 'active'])->save();
    $this->cashier = staff($this->owner, 'efua', 2, 'teller', ScopeType::Till, $this->till->id);
    $this->shift = app(ShiftService::class)->open($this->device, $this->cashier, 0);
    $this->seq = 0;
    $this->inv = app(InventoryService::class);
    // Two batches: 10 expiring in 5 days, 20 expiring in 40 days (opening stock at 6.00).
    $this->inv->adjust(actorFor($this->owner), $this->wh, $this->yoghurt, $this->unit, '10', 'opening', '', null, '6.00', 'B-OLD', now()->addDays(5)->toDateString());
    $this->inv->adjust(actorFor($this->owner), $this->wh, $this->yoghurt, $this->unit, '20', 'adjustment', 'found', null, '6.00', 'B-NEW', now()->addDays(40)->toDateString());
});

function batchQty($t, string $batchNo): string
{
    return (string) DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')
        ->where('l.warehouse_id', $t->wh->id)->where('b.batch_no', $batchNo)->value('l.on_hand');
}

/** A till sale (optionally with markdown lines: [product, qty, markdown]). */
function groSale($t, array $items, bool $offline = false, ?string $at = null): Sale
{
    $lines = [];
    $engine = [];
    foreach ($items as $i => $it) {
        [$p, $qty] = $it;
        $md = $it[2] ?? null;
        $unit = $p->units->first();
        $price = $md ? $md->price_minor : app(PriceResolver::class)->resolve($t->retail, $p->id, $unit->id, Qty::toMilli($qty), now())->amount_minor;
        $engine[] = ['key' => (string) $i, 'product_id' => $md ? 'markdown:'.$md->id : $p->id, 'category_id' => $md ? null : $p->category_id, 'qty_milli' => Qty::toMilli($qty),
            'unit_price_minor' => $price, 'tax' => $p->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all(), 'manual' => null];
        $lines[] = ['product_id' => $p->id, 'product_uom_id' => $unit->id, 'qty' => $qty, 'unit_price_minor' => $price] + ($md ? ['markdown_id' => $md->id] : []);
    }
    $calc = PromotionEngine::compute(['lines' => $engine, 'inclusive' => true, 'coupon' => null, 'sale_discount_bp' => 0,
        'promotions' => PromotionService::active($t->hq->id, $t->retail->id, now())]);
    foreach ($calc['lines'] as $i => $r) {
        $lines[$i]['line_total_minor'] = $r['total'];
    }
    $payload = ['id' => (string) Str::uuid7(), 'device_seq' => ++$t->seq, 'occurred_at' => $at ?? now()->toIso8601String(), 'price_list_id' => $t->retail->id,
        'shift_id' => $t->shift->id, 'lines' => $lines, 'totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $calc['total']],
        'payments' => [['method' => 'cash', 'amount_minor' => $calc['total'], 'tendered_minor' => $calc['total']]]];
    if ($offline) {
        $grant = app(OfflineGrant::class)->issue($t->cashier, $t->device, '482613');

        return app(SaleService::class)->post($t->device, null, $payload, app(OfflineGrant::class)->verify($grant))['sale'];
    }

    return app(SaleService::class)->post($t->device, $t->cashier, $payload)['sale'];
}

it('sells the earliest expiry first across batches, takes returns back into their batch, and keeps the ledger in agreement', function () {
    $sale = groSale($this, [[$this->yoghurt, '12']]);
    expect(batchQty($this, 'B-OLD'))->toBe('0.000')->and(batchQty($this, 'B-NEW'))->toBe('18.000')
        ->and(DB::table('stock_movements')->where('type', 'sale')->whereNotNull('batch_id')->count())->toBe(2)
        ->and(app(StockLedger::class)->check())->toBe([]);

    // A return goes back into the batch it was sold from.
    $ret = app(ReturnService::class);
    $line = collect($ret->returnable($sale->fresh('lines', 'payments'))['lines'])->first();
    $ret->post($this->device, $this->cashier, ['id' => (string) Str::uuid7(), 'device_seq' => ++$this->seq, 'occurred_at' => now()->toIso8601String(),
        'shift_id' => $this->shift->id, 'original_sale_id' => $sale->id, 'reason' => 'Wrong flavour', 'lines' => [['original_line_id' => $line['id'], 'qty' => '1']],
        'refunds' => [['method' => 'cash', 'amount_minor' => 1000]]]);
    expect(batchQty($this, 'B-OLD'))->toBe('1.000');

    // Receiving needs the batch from the pack; an expired batch is refused at the door.
    $supplier = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['code' => 'FAN', 'name' => 'FanMilk']);
    $grn = fn (array $line) => app(GoodsReceiptService::class)->receive(actorFor($this->owner), null, ['warehouse_id' => $this->wh->id, 'supplier_id' => $supplier->id,
        'lines' => [['product_uom_id' => $this->unit->id, 'qty' => '6', 'unit_cost' => '6.00'] + $line]]);
    expect(fn () => $grn([]))->toThrow(DomainException::class, 'batch number')
        ->and(fn () => $grn(['batch_no' => 'B-BAD', 'expires_on' => now()->subDay()->toDateString()]))->toThrow(DomainException::class, 'already expired');
    $grn(['batch_no' => 'b-fresh', 'expires_on' => now()->addDays(60)->toDateString()]);
    expect(batchQty($this, 'B-FRESH'))->toBe('6.000');
});

it('never sells expired stock online, keeps an offline sale of it and flags it', function () {
    Carbon::setTestNow(now()->addDays(6)); // B-OLD has expired
    groSale($this, [[$this->yoghurt, '15']]); // only the 20 good ones are used
    expect(batchQty($this, 'B-OLD'))->toBe('10.000')->and(batchQty($this, 'B-NEW'))->toBe('5.000');
    expect(fn () => groSale($this, [[$this->yoghurt, '8']]))->toThrow(DomainException::class, 'Only expired stock');

    app(AccessService::class)->setPin(actorFor($this->owner), $this->cashier, '482613');
    $sale = groSale($this, [[$this->yoghurt, '8']], offline: true);
    expect(batchQty($this, 'B-NEW'))->toBe('0.000')->and(batchQty($this, 'B-OLD'))->toBe('7.000')
        ->and(ReviewItem::where('type', 'expired_sold')->where('subject_id', $sale->id)->exists())->toBeTrue();
    Carbon::setTestNow();
});

it('suggests markdowns near expiry, sells the sticker at its price from its batch, and writes expired stock off as shrink', function () {
    $svc = app(MarkdownService::class);
    $s = collect($svc->suggestions($this->tenant->id, [$this->wh->id]));
    expect($s)->toHaveCount(1)->and($s[0]->batch_no)->toBe('B-OLD')->and($s[0]->suggested_bp)->toBe(3000); // 5 days left → 30 %

    $md = $svc->apply(actorFor($this->owner), $this->wh, $s[0]->batch_id, 3000, 4);
    expect(Ean13::valid($md->code))->toBeTrue()->and($md->code)->toStartWith('29')->and($md->price_minor)->toBe(700)
        ->and(MarkdownService::activeFor($this->hq->id))->toHaveCount(1);

    // A promotion on yoghurt does not stack on the sticker; the sticker comes from B-OLD even though FEFO would agree anyway.
    app(PromotionService::class)->save(actorFor($this->owner), new Promotion, ['name' => 'Yogo 10%', 'type' => 'percent', 'product_ids' => [$this->yoghurt->id], 'value_bp' => 1000]);
    $sale = groSale($this, [[$this->yoghurt, '2', $md], [$this->yoghurt, '1']]);
    expect($sale->total_minor)->toBe(2 * 700 + 900)->and($sale->lines[0]->markdown_id)->toBe($md->id)->and(batchQty($this, 'B-OLD'))->toBe('7.000');

    // A withdrawn sticker is refused online.
    $svc->withdraw(actorFor($this->owner), $md, 'Sold out');
    expect(fn () => groSale($this, [[$this->yoghurt, '1', $md->fresh()]]))->toThrow(DomainException::class, 'no longer valid');

    // After expiry: written off as shrink, reported at cost.
    Carbon::setTestNow(now()->addDays(6));
    $svc->writeOffExpired(actorFor($this->owner), $this->wh, $s[0]->batch_id);
    expect(batchQty($this, 'B-OLD'))->toBe('0.000');
    $page = new ShrinkReport;
    $page->from = now()->subMonth()->toDateString();
    $page->to = now()->toDateString();
    $this->actingAs($this->owner);
    expect((int) $page->byReason()->firstWhere('reason', 'Expired')->value)->toBe(7 * 600)->and(app(StockLedger::class)->check())->toBe([]);
    Carbon::setTestNow();
});

it('moves batches with transfers and batches existing stock when tracking starts', function () {
    $ba = Warehouse::where('branch_id', $this->branches['BA']->id)->first();
    $tr = app(TransferService::class);
    $t = $tr->saveDraft(actorFor($this->owner), new StockTransfer, ['from_warehouse_id' => $this->wh->id, 'to_warehouse_id' => $ba->id,
        'lines' => [['product_uom_id' => $this->unit->id, 'qty' => '14']]]);
    $tr->dispatch(actorFor($this->owner), $t);
    $tr->receive(actorFor($this->owner), $t->fresh());
    $at = fn (string $no) => (string) DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')->where('l.warehouse_id', $ba->id)->where('b.batch_no', $no)->value('l.on_hand');
    expect($at('B-OLD'))->toBe('10.000')->and($at('B-NEW'))->toBe('4.000')->and(app(StockLedger::class)->check())->toBe([]);

    // Tracking switched on for a product that already has stock: it becomes batch OPENING, with no value moved.
    $milk = makeProduct($this->owner, 'MILK', 'Fresh milk 1L', retail: '15.00');
    $this->inv->adjust(actorFor($this->owner), $this->wh, $milk, $milk->units->first(), '9', 'opening', '', null, '11.00');
    app(CatalogService::class)->saveProduct(actorFor($this->owner), $milk->fresh(), ['track_batches' => true]);
    expect((string) DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')->where('b.batch_no', 'OPENING')->value('l.on_hand'))->toBe('9.000')
        ->and(app(StockLedger::class)->check())->toBe([]);
    app(AutoPoster::class)->run($this->tenant->id);
    expect(app(GlCheck::class)->run($this->tenant->id)['ok'])->toBeTrue();
});

it('gives grocery tills their scale and sticker settings only where the module is on, and sends shelf prices to the ESL gateway', function () {
    app(CatalogService::class)->saveProduct(actorFor($this->owner), $this->yoghurt, ['plu' => '00123']);
    expect(fn () => app(CatalogService::class)->saveProduct(actorFor($this->owner), makeProduct($this->owner, 'BAN', 'Bananas', retail: '8.00'), ['plu' => '00123']))
        ->toThrow(DomainException::class, 'PLU');
    $catalog = function (Till $till) {
        [$device, $code] = app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $till->branch_id, 'till_id' => $till->id, 'name' => 'X'.Str::random(3), 'kind' => 'pos']);
        $token = $this->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->json('token');
        app('auth')->forgetGuards();
        $login = $this->withToken($token)->postJson('/api/v1/pos/login', ['username' => 'efua2', 'pin' => '482613'])->json('token');
        app('auth')->forgetGuards();

        return $this->withToken($login)->getJson('/api/v1/pos/catalog')->json();
    };
    $u = staff($this->owner, 'efua2', 2, 'teller');
    app(AccessService::class)->setPin(actorFor($this->owner), $u, '482613');
    $hq = $catalog($this->till);
    expect($hq['grocery'])->toMatchArray(['scale_prefixes' => ['21'], 'markdown_prefix' => '29'])
        ->and(collect($hq['products'])->firstWhere('sku', 'YOG')['plu'])->toBe('00123');
    app(ModuleService::class)->setStatus(actorFor($this->owner), $this->branches['BA'], 'grocery', 'disabled');
    expect($catalog(Till::where('branch_id', $this->branches['BA']->id)->first())['grocery'])->toBeNull(); // module off at branch A

    app(Settings::class)->set(actorFor($this->owner), 'grocery.esl_webhook_url', 'https://esl.example/prices');
    Http::fake(['esl.example/*' => Http::response(['ok' => true])]);
    DB::table('outbox_cursors')->insert(['consumer' => 'esl', 'last_seq' => 0, 'updated_at' => now()]);
    app(EslRelay::class)->run();
    Http::assertSent(fn ($r) => $r->url() === 'https://esl.example/prices' && collect($r['items'])->contains(fn ($i) => $i['sku'] === 'YOG' && $i['price_minor'] === 1000));
});
