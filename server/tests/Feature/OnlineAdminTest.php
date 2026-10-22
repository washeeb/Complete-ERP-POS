<?php

use App\Domain\Channels\ChannelAdmin;
use App\Domain\Channels\OnlineOrderService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Support\DomainException;
use App\Filament\Resources\SalesChannels\Pages\CreateSalesChannel;
use App\Filament\Resources\SalesChannels\Pages\EditSalesChannel;
use App\Filament\Resources\SalesChannels\Pages\ListSalesChannels;
use App\Filament\Resources\SalesChannels\SalesChannelResource;
use App\Filament\Resources\SalesOrders\Pages\ListSalesOrders;
use App\Filament\Resources\SalesOrders\Pages\ViewSalesOrder;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Models\PriceList;
use App\Models\SalesChannel;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    enableModule($this->owner, $this->branches['BA'], 'storefront');
    $this->wh = Warehouse::where('branch_id', $this->branches['BA']->id)->first();
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->wh, $this->milo, $this->milo->units->first(), '10', 'opening', '');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner));
});

it('sets up a WooCommerce channel, keeps its secrets encrypted and never shows them again', function () {
    Livewire::test(CreateSalesChannel::class)
        ->fillForm(['type' => 'woocommerce', 'name' => 'Woo shop', 'branch_id' => $this->branches['BA']->id, 'warehouse_id' => $this->wh->id,
            'price_list_id' => $this->retail->id, 'safety_stock' => '2', 'base_url' => 'https://shop.example.test',
            'secrets' => ['woocommerce' => ['consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test', 'webhook_secret' => 'whsec_test']]])
        ->call('create')->assertHasNoFormErrors();

    $c = SalesChannel::sole();
    expect($c->credentials)->toBe(['consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test'])->and($c->webhook_secret)->toBe('whsec_test')
        ->and(DB::table('sales_channels')->value('credentials'))->not->toContain('ck_test');

    $this->get(SalesChannelResource::getUrl('edit', ['record' => $c]))->assertOk()->assertDontSee('ck_test')->assertDontSee('whsec_test')->assertSee('Saved. Leave blank to keep it.');
    Livewire::test(EditSalesChannel::class, ['record' => $c->id])->fillForm(['name' => 'Woo store'])->call('save')->assertHasNoFormErrors();
    expect($c->fresh()->credentials['consumer_key'])->toBe('ck_test')->and($c->fresh()->name)->toBe('Woo store');

    $this->get(SalesChannelResource::getUrl())->assertOk()->assertSee('Woo store')->assertSee('draft')->assertSee("/api/v1/channels/{$c->id}/webhooks");
});

it('activates the built-in shop from the list and shows its address', function () {
    $c = app(ChannelAdmin::class)->save(actorFor($this->owner), new SalesChannel, ['type' => 'storefront', 'name' => 'Osu Shop',
        'branch_id' => $this->branches['BA']->id, 'warehouse_id' => $this->wh->id, 'price_list_id' => $this->retail->id]);

    Livewire::test(ListSalesChannels::class)->callTableAction('activate', $c)->assertNotified('Activated');

    expect($c->fresh()->status)->toBe('active');
    $this->get(SalesChannelResource::getUrl())->assertSee(url('/shop/osu-shop'));
});

it('refuses a shop address another shop already uses', function () {
    $data = ['type' => 'storefront', 'branch_id' => $this->branches['BA']->id, 'warehouse_id' => $this->wh->id, 'price_list_id' => $this->retail->id];
    app(ChannelAdmin::class)->save(actorFor($this->owner), new SalesChannel, $data + ['name' => 'Osu Shop']);
    app(ChannelAdmin::class)->save(actorFor($this->owner), new SalesChannel, $data + ['name' => 'Osu  shop']);
})->throws(DomainException::class, 'already taken');

it('shows online orders and hands one over with the payment recorded', function () {
    $shop = app(ChannelAdmin::class)->activate(actorFor($this->owner), app(ChannelAdmin::class)->save(actorFor($this->owner), new SalesChannel, [
        'type' => 'storefront', 'name' => 'Osu Shop', 'branch_id' => $this->branches['BA']->id, 'warehouse_id' => $this->wh->id, 'price_list_id' => $this->retail->id]));
    $o = app(OnlineOrderService::class)->ingest($shop, ['external_id' => 'x1', 'number' => 'WEB-X1', 'customer' => ['name' => 'Akua', 'phone' => '0241234567'],
        'payment_status' => 'pay_at_pickup', 'lines' => [['sku' => 'MILO', 'qty' => '2', 'unit_price_minor' => 5800]]])['order'];

    $this->get(SalesOrderResource::getUrl())->assertOk()->assertSee('WEB-X1')->assertSee('Akua')->assertSee('116.00')->assertSee('Pay at pickup');
    $this->get(SalesOrderResource::getUrl('view', ['record' => $o]))->assertOk()->assertSee('MILO · MILO 400G')->assertSee('Reserved');

    Livewire::test(ListSalesOrders::class)->callTableAction('ready', $o)->assertNotified('Marked ready for pickup');
    Livewire::test(ViewSalesOrder::class, ['record' => $o->id])
        ->callAction('fulfil', ['payment_method' => 'momo', 'payment_reference' => '58123499'])->assertNotified('Handed over');

    expect($o->fresh()->status)->toBe('fulfilled')->and($o->fresh()->payment_reference)->toBe('58123499');
});

it('shows online orders only for the branches a person may see', function () {
    $shop = app(ChannelAdmin::class)->activate(actorFor($this->owner), app(ChannelAdmin::class)->save(actorFor($this->owner), new SalesChannel, [
        'type' => 'storefront', 'name' => 'Osu Shop', 'branch_id' => $this->branches['BA']->id, 'warehouse_id' => $this->wh->id, 'price_list_id' => $this->retail->id]));
    app(OnlineOrderService::class)->ingest($shop, ['external_id' => 'x2', 'number' => 'WEB-X2', 'customer' => ['name' => 'Kofi'],
        'payment_status' => 'pay_at_pickup', 'lines' => [['sku' => 'MILO', 'qty' => '1', 'unit_price_minor' => 5800]]]);
    $otherBranchCs = staff($this->owner, 'cs1', 2, 'customer_service', ScopeType::Branch, $this->branches['HQ']->id);

    $this->actingAs(withMfa($otherBranchCs));
    $this->get(SalesOrderResource::getUrl())->assertOk()->assertDontSee('WEB-X2');
    $this->get(SalesChannelResource::getUrl())->assertForbidden();
});
