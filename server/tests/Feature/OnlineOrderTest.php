<?php

use App\Domain\Channels\ChannelAdmin;
use App\Domain\Channels\ChannelSync;
use App\Domain\Channels\OnlineOrderService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockAvailability;
use App\Domain\Inventory\StockLedger;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\PriceList;
use App\Models\ReviewItem;
use App\Models\SalesChannel;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Security\ScopeType;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    enableModule($this->owner, $this->branches['BA'], 'storefront');
    $this->wh = Warehouse::where('branch_id', $this->branches['BA']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->wh, $this->milo, $this->milo->units->first(), '10', 'opening', '');
    $this->shop = app(ChannelAdmin::class)->save(actorFor($this->owner), new SalesChannel, [
        'type' => 'storefront', 'name' => 'Osu online shop', 'branch_id' => $this->branches['BA']->id, 'warehouse_id' => $this->wh->id,
        'price_list_id' => PriceList::where('code', 'RETAIL')->value('id'), 'safety_stock' => '2',
    ]);
    app(ChannelAdmin::class)->activate(actorFor($this->owner), $this->shop);
    $this->orders = app(OnlineOrderService::class);
});

function order(string $id, string $qty, int $price = 5800): array
{
    return ['external_id' => $id, 'number' => "WEB-{$id}", 'customer' => ['name' => 'Akua', 'phone' => '0241234567'],
        'payment_status' => 'pay_at_pickup', 'lines' => [['sku' => 'MILO', 'qty' => $qty, 'unit_price_minor' => $price]]];
}

function available($t): int
{
    return app(StockAvailability::class)->available($t->wh->id, [$t->milo->id], 2000)[$t->milo->id];
}

it('reserves stock for an online order, keeping the safety buffer back from the store', function () {
    expect(available($this))->toBe(8000); // 10 on hand − 2 safety

    $o = $this->orders->ingest($this->shop->fresh(), order('1001', '3'))['order'];

    expect($o->status)->toBe('confirmed')->and($o->total_minor)->toBe(17400)->and($o->short_stock)->toBeFalse()
        ->and(available($this))->toBe(5000)
        ->and(app(ChannelSync::class)->stateFor($this->shop, $this->milo, $this->milo->units->first())['available'])->toBe(5);
});

it('accepts the same order once, however often the store resends it', function () {
    $this->orders->ingest($this->shop->fresh(), order('1001', '3'));
    $again = $this->orders->ingest($this->shop->fresh(), order('1001', '3'));

    expect($again['created'])->toBeFalse()->and(SalesOrder::count())->toBe(1)->and(available($this))->toBe(5000);
});

it('flags an order bigger than what may be promised, without losing it', function () {
    $o = $this->orders->ingest($this->shop->fresh(), order('1002', '9'))['order']; // 9 > 8 promisable

    expect($o->short_stock)->toBeTrue()
        ->and(ReviewItem::where('type', 'oversell')->where('subject_id', $o->id)->exists())->toBeTrue();
});

it('issues stock only when fulfilled, and needs the payment recorded first', function () {
    $o = $this->orders->ingest($this->shop->fresh(), order('1003', '2'))['order'];
    expect(StockMovement::where('source_type', 'sales_order')->count())->toBe(0);

    expect(fn () => $this->orders->fulfil(actorFor($this->owner), $o->fresh()))->toThrow(DomainException::class, 'how the customer paid');
    $this->orders->fulfil(actorFor($this->owner), $o->fresh(), 'momo', '58123499');

    $o->refresh();
    expect($o->status)->toBe('fulfilled')->and($o->payment_status)->toBe('paid')->and($o->payment_reference)->toBe('58123499')
        ->and((string) DB::table('stock_levels')->where('warehouse_id', $this->wh->id)->where('product_id', $this->milo->id)->value('on_hand'))->toBe('8.000')
        ->and(available($this))->toBe(6000) // reservation consumed: 8 on hand − 2 safety
        ->and(app(StockLedger::class)->check())->toBe([]);
});

it('releases the reservation when an order is cancelled', function () {
    $o = $this->orders->ingest($this->shop->fresh(), order('1004', '4'))['order'];
    expect(available($this))->toBe(4000);

    $this->orders->cancel(actorFor($this->owner), $o->fresh(), 'Customer changed mind');

    expect($o->fresh()->status)->toBe('cancelled')->and(available($this))->toBe(8000);
});

it('flags a store total that differs from the items (e.g. shipping not modelled)', function () {
    $in = order('1005', '1') + ['total_minor' => 5800 + 1500];
    $o = $this->orders->ingest($this->shop->fresh(), $in)['order'];

    expect(ReviewItem::where('type', 'channel_total')->where('subject_id', $o->id)->exists())->toBeTrue();
});

it('refuses lines it cannot match to a product', function () {
    $in = order('1006', '1');
    $in['lines'][0]['sku'] = 'NOPE';
    $this->orders->ingest($this->shop->fresh(), $in);
})->throws(DomainException::class, 'not matched');

it('limits order handling to people who may manage orders at that branch', function () {
    $o = $this->orders->ingest($this->shop->fresh(), order('1007', '1'))['order'];
    $cashier = staff($this->owner, 'c1', 2, 'cashier', ScopeType::Branch, $this->branches['BA']->id);

    $this->orders->cancel(actorFor($cashier), $o->fresh(), 'x');
})->throws(AccessDenied::class);

it('protects online orders taken while the branch is offline: the safety buffer absorbs offline till sales', function () {
    // Spec §4.3 "online order during branch outage". HQ still sees 10 Milo for branch A; the store may promise 8.
    $o = $this->orders->ingest($this->shop->fresh(), order('2001', '8'))['order'];
    expect($o->short_stock)->toBeFalse();

    // Meanwhile branch A's tills, offline, sold 2 — those movements arrive later by sync.
    app(StockLedger::class); // (the same movements a till/branch sync would add)
    DB::transaction(fn () => app(StockLedger::class)->post(Actor::system($this->tenant->id, 'Sync from B01', 'sync'),
        $this->wh, 'sale', [['product' => $this->milo, 'unit' => $this->milo->units->first(), 'qty_milli' => -2000]], 'Offline sales', null, null, now(), alreadyHappened: true));

    // Everything promised online can still be handed over: 10 − 2 sold offline = 8 on hand for 8 reserved.
    $this->orders->fulfil(actorFor($this->owner), $o->fresh(), 'cash');
    expect((string) DB::table('stock_levels')->where('warehouse_id', $this->wh->id)->where('product_id', $this->milo->id)->value('on_hand'))->toBe('0.000')
        ->and(ReviewItem::where('type', 'negative_stock')->exists())->toBeFalse();
});
