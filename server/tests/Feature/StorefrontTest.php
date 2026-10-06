<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Channels\ChannelAdmin;
use App\Domain\Inventory\InventoryService;
use App\Domain\Modules\ModuleService;
use App\Domain\Support\DomainException;
use App\Models\PriceList;
use App\Models\SalesChannel;
use App\Models\SalesOrder;
use App\Models\StockReservation;
use App\Models\Warehouse;

beforeEach(function () {
    $this->withoutVite();
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    enableModule($this->owner, $this->branches['BA'], 'storefront');
    $this->wh = Warehouse::where('branch_id', $this->branches['BA']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->wh, $this->milo, $this->milo->units->first(), '5', 'opening', '');
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $this->shop = app(ChannelAdmin::class)->save(actorFor($this->owner), new SalesChannel, [
        'type' => 'storefront', 'name' => 'Osu Shop', 'branch_id' => $this->branches['BA']->id, 'warehouse_id' => $this->wh->id,
        'price_list_id' => $this->retail->id, 'safety_stock' => '1', 'settings' => ['headline' => 'Fresh from Osu'],
    ]);
});

it('stays hidden until the channel is activated', function () {
    $this->get('/shop/osu-shop')->assertNotFound();
    app(ChannelAdmin::class)->activate(actorFor($this->owner), $this->shop);
    $this->get('/shop/osu-shop')->assertOk()->assertSee('Fresh from Osu')->assertSee('Milo 400g')->assertSee('58.00')->assertSee('4 available');
});

it('shows the current server price, never one sent by the browser', function () {
    app(ChannelAdmin::class)->activate(actorFor($this->owner), $this->shop);
    app(CatalogService::class)->saveProduct(actorFor($this->owner), $this->milo, ['prices' => [
        ['price_list_id' => $this->retail->id, 'uom_id' => $this->milo->units->first()->uom_id, 'min_qty' => '1', 'amount' => '61.50'],
    ]]);
    $this->post('/shop/osu-shop/cart', ['product_id' => $this->milo->id, 'qty' => 2, 'price' => 1])->assertRedirect();

    $this->get('/shop/osu-shop/cart')->assertOk()->assertSee('61.50')->assertSee('123.00');
});

it('will not add more than may be promised', function () {
    app(ChannelAdmin::class)->activate(actorFor($this->owner), $this->shop);
    $this->post('/shop/osu-shop/cart', ['product_id' => $this->milo->id, 'qty' => 5])->assertSessionHas('error', 'Only 4 Milo 400g available.');
});

it('places a pay-at-pickup order once, reserves the stock and shows a signed confirmation', function () {
    app(ChannelAdmin::class)->activate(actorFor($this->owner), $this->shop);
    $this->post('/shop/osu-shop/cart', ['product_id' => $this->milo->id, 'qty' => 2]);
    $token = (string) Str::uuid7();
    $form = ['name' => 'Akua Mensah', 'phone' => '024 123 4567', 'token' => $token];

    $r = $this->post('/shop/osu-shop/checkout', $form);
    $this->post('/shop/osu-shop/cart', ['product_id' => $this->milo->id, 'qty' => 2]);
    $this->post('/shop/osu-shop/checkout', $form); // double submit: same token

    $o = SalesOrder::sole();
    expect($o->payment_status)->toBe('pay_at_pickup')->and($o->total_minor)->toBe(11600)->and($o->customer_name)->toBe('Akua Mensah')
        ->and(StockReservation::where('status', 'active')->sum('qty_base'))->toEqual(2);
    $this->get($r->headers->get('Location'))->assertOk()->assertSee($o->number)->assertSee('116.00');
    $this->get("/shop/osu-shop/order/{$o->id}")->assertForbidden(); // unsigned link
});

it('silently rejects bots that fill the hidden field', function () {
    app(ChannelAdmin::class)->activate(actorFor($this->owner), $this->shop);
    $this->post('/shop/osu-shop/cart', ['product_id' => $this->milo->id]);
    $this->post('/shop/osu-shop/checkout', ['name' => 'Bot', 'phone' => '0241234567', 'token' => (string) Str::uuid7(), 'website' => 'http://spam.test'])
        ->assertSessionHasErrors('website');
    expect(SalesOrder::count())->toBe(0);
});

it('closes the shop when the branch turns the Storefront module off, and will not set one up without it', function () {
    app(ChannelAdmin::class)->activate(actorFor($this->owner), $this->shop);
    app(ModuleService::class)->setStatus(actorFor($this->owner), $this->branches['BA'], 'storefront', 'disabled');

    $this->get('/shop/osu-shop')->assertNotFound();
    expect(fn () => app(ChannelAdmin::class)->save(actorFor($this->owner), $this->shop->fresh(), ['name' => 'Osu Shop 2']))
        ->toThrow(DomainException::class, 'Turn on the Online Storefront module');
});
