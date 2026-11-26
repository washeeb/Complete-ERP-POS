<?php

use App\Domain\Channels\ChannelAdmin;
use App\Domain\Channels\OnlineOrderService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Messaging\ProviderSetup;
use App\Models\MessageProvider;
use App\Models\PriceList;
use App\Models\SalesChannel;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Models\WhatsappOrder;
use App\Security\ScopeType;
use App\Support\Settings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

// Online in the new back office (part 33): online orders, channels and WhatsApp orders.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    withMfa($this->owner);
    enableModule($this->owner, $this->b['BA'], 'storefront');
    $this->wh = Warehouse::where('branch_id', $this->b['BA']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->wh, $this->milo, $this->milo->units->first(), '10', 'opening', '');
    $this->retail = PriceList::where('code', 'RETAIL')->value('id');
});

function officeShop($t): SalesChannel
{
    $shop = app(ChannelAdmin::class)->save(actorFor($t->owner), new SalesChannel, ['type' => 'storefront', 'name' => 'Osu online shop', 'branch_id' => $t->b['BA']->id,
        'warehouse_id' => $t->wh->id, 'price_list_id' => $t->retail, 'safety_stock' => '0']);
    app(ChannelAdmin::class)->activate(actorFor($t->owner), $shop);

    return $shop->fresh();
}

function officeWebOrder(string $id, string $qty): array
{
    return ['external_id' => $id, 'number' => "WEB-{$id}", 'customer' => ['name' => 'Akua', 'phone' => '0241234567'], 'payment_status' => 'pay_at_pickup',
        'lines' => [['sku' => 'MILO', 'qty' => $qty, 'unit_price_minor' => 5800]]];
}

it('lists online orders and marks one ready, hands it over and cancels another', function () {
    $shop = officeShop($this);
    $orders = app(OnlineOrderService::class);
    $a = $orders->ingest($shop, officeWebOrder('1001', '2'))['order'];
    $b = $orders->ingest($shop, officeWebOrder('1002', '1'))['order'];
    $this->actingAs($this->owner, 'web');

    $list = $this->getJson('/office-api/online/orders')->assertOk()->json();
    expect($list['total'])->toBe(2)->and(collect($list['rows'])->firstWhere('id', $a->id))->toMatchArray(['number' => 'WEB-1001', 'customer' => 'Akua', 'status' => 'confirmed', 'paid' => false]);
    $one = $this->getJson("/office-api/online/orders/{$a->id}")->assertOk()->json();
    expect($one['lines'][0])->toMatchArray(['item' => 'MILO · MILO 400G'])->and($one['actions'])->toBe(['ready' => true, 'fulfil' => true, 'cancel' => true]);

    $this->postJson("/office-api/online/orders/{$a->id}/action", ['action' => 'ready'])->assertOk();
    expect(SalesOrder::find($a->id)->status)->toBe('ready');
    $this->postJson("/office-api/online/orders/{$a->id}/action", ['action' => 'fulfil'])->assertStatus(422); // how was it paid?
    $this->postJson("/office-api/online/orders/{$a->id}/action", ['action' => 'fulfil', 'payment_method' => 'momo', 'payment_reference' => 'MP-1'])->assertOk();
    expect(SalesOrder::find($a->id)->status)->toBe('fulfilled');
    $this->postJson("/office-api/online/orders/{$b->id}/action", ['action' => 'cancel', 'reason' => 'Customer called'])->assertOk();
    expect(SalesOrder::find($b->id)->only(['status', 'cancel_reason']))->toBe(['status' => 'cancelled', 'cancel_reason' => 'Customer called']);
    expect($this->getJson('/office-api/online/orders')->json('total'))->toBe(0)->and($this->getJson('/office-api/online/orders?status=all')->json('total'))->toBe(2);

    $cashier = staff($this->owner, 'yaa', 3, 'cashier', ScopeType::Branch, $this->b['HQ']->id);
    $this->actingAs($cashier, 'web');
    $this->getJson('/office-api/online/channels')->assertForbidden();
});

it('adds, changes, activates, pauses and archives channels, never sending their secrets back', function () {
    $this->actingAs($this->owner, 'web');
    $form = $this->getJson('/office-api/online/channels/form')->assertOk()->json();
    expect(collect($form['types'])->pluck('id'))->toContain('storefront', 'woocommerce')
        ->and(collect($form['secrets']['woocommerce'])->pluck('id'))->toContain('webhook_secret');
    $base = ['name' => 'Osu online shop', 'branch_id' => $this->b['BA']->id, 'warehouse_id' => $this->wh->id, 'price_list_id' => $this->retail];
    $shop = $this->postJson('/office-api/online/channels', $base + ['type' => 'storefront', 'slug' => 'osu', 'headline' => 'Groceries in Osu'])->assertOk()->json('id');
    expect(SalesChannel::find($shop)->settings)->toMatchArray(['slug' => 'osu', 'headline' => 'Groceries in Osu']);
    $this->postJson('/office-api/online/channels', ['type' => 'storefront', 'warehouse_id' => Warehouse::where('branch_id', $this->b['HQ']->id)->value('id')] + $base)
        ->assertStatus(422); // the warehouse is not the branch's

    $woo = $this->postJson('/office-api/online/channels', ['type' => 'woocommerce', 'name' => 'Woo shop', 'base_url' => 'https://shop.example.com',
        'secrets' => ['consumer_key' => 'ck_live_secret', 'consumer_secret' => 'cs_live_secret', 'webhook_secret' => 'wh-secret']] + $base)->assertOk()->json('id');
    $edit = $this->getJson("/office-api/online/channels/{$woo}")->assertOk()->json();
    expect(collect($edit['secrets']['woocommerce'])->every(fn ($f) => $f['saved']))->toBeTrue()
        ->and(json_encode($edit))->not->toContain('ck_live_secret')->not->toContain('wh-secret');
    // Changing it with the secrets left blank keeps them.
    $this->postJson("/office-api/online/channels/{$woo}", ['name' => 'Woo shop 2', 'base_url' => 'https://shop.example.com', 'secrets' => ['consumer_key' => '']] + $base)->assertOk();
    expect(SalesChannel::find($woo)->name)->toBe('Woo shop 2')->and(SalesChannel::find($woo)->credentials['consumer_key'])->toBe('ck_live_secret');

    $this->postJson("/office-api/online/channels/{$shop}/action", ['action' => 'activate'])->assertOk();
    $rows = collect($this->getJson('/office-api/online/channels')->assertOk()->json('rows'))->keyBy('id');
    expect($rows[$shop])->toMatchArray(['status' => 'active', 'updates' => 'live', 'address' => url('/shop/osu')]);
    $this->postJson("/office-api/online/channels/{$shop}/action", ['action' => 'pause'])->assertStatus(422);
    $this->postJson("/office-api/online/channels/{$shop}/action", ['action' => 'pause', 'reason' => 'Stocktake'])->assertOk();
    expect(SalesChannel::find($shop)->status)->toBe('paused');
    $this->postJson("/office-api/online/channels/{$woo}/archive", ['reason' => 'Moved to Shopify'])->assertOk();
    expect(collect($this->getJson('/office-api/online/channels')->json('rows'))->pluck('id')->all())->toBe([$shop]);
});

it('turns a WhatsApp message into an order, replies to and declines others', function () {
    $o = actorFor($this->owner);
    app(ProviderSetup::class)->save($o, 'whatsapp', ['credentials' => ['phone_number_id' => '1234', 'access_token' => 'tok', 'app_secret' => 'shh'], 'is_active' => true]);
    app(Settings::class)->set($o, 'whatsapp.orders', true);
    Storage::fake('local');
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]])]);
    $provider = MessageProvider::where('channel', 'whatsapp')->sole();
    $send = function (string $text, string $from) use ($provider) {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Ama']]], 'messages' => [['id' => 'wamid.'.$from, 'from' => $from, 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => $text]]]]]]]]]);

        return $this->call('POST', '/api/v1/whatsapp/'.$provider->id, [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'shh')], $body);
    };
    $send('Two Milo please', '233241112222')->assertOk();
    $send('Do you sell insulin?', '233249998888')->assertOk();
    [$wantMilo, $askInsulin] = [WhatsappOrder::where('from_phone', '233241112222')->sole(), WhatsappOrder::where('from_phone', '233249998888')->sole()];

    $this->actingAs($this->owner, 'web');
    $list = $this->getJson('/office-api/online/whatsapp')->assertOk()->json();
    expect($list['total'])->toBe(2)->and(collect($list['rows'])->firstWhere('id', $wantMilo->id))->toMatchArray(['body' => 'Two Milo please', 'status' => 'new']);
    $one = $this->getJson("/office-api/online/whatsapp/{$wantMilo->id}")->assertOk()->json();
    expect($one['actions'])->toMatchArray(['make_order' => true, 'decline' => true])->and(collect($one['branches'])->pluck('id'))->toContain($this->b['BA']->id);

    $unit = collect($this->getJson('/office-api/online/whatsapp/units?q=milo')->assertOk()->json('results'))->firstWhere('product_id', $this->milo->id);
    $this->postJson("/office-api/online/whatsapp/{$wantMilo->id}/action", ['action' => 'make_order', 'branch_id' => $this->b['BA']->id, 'fulfilment' => 'delivery',
        'lines' => [['product_uom_id' => $unit['id'], 'qty' => '2']]])->assertStatus(422); // where to deliver?
    $open = $this->postJson("/office-api/online/whatsapp/{$wantMilo->id}/action", ['action' => 'make_order', 'branch_id' => $this->b['BA']->id, 'fulfilment' => 'pickup',
        'lines' => [['product_uom_id' => $unit['id'], 'qty' => '2']]])->assertOk()->json('open');
    $order = SalesOrder::find($wantMilo->fresh()->sales_order_id);
    expect($open)->toBe("/office/online/orders/{$order->id}")->and($wantMilo->fresh()->status)->toBe('ordered')->and($order->total_minor)->toBe(11600);

    $this->postJson("/office-api/online/whatsapp/{$askInsulin->id}/action", ['action' => 'reply', 'text' => 'Which strength?'])->assertOk();
    $this->postJson("/office-api/online/whatsapp/{$askInsulin->id}/action", ['action' => 'decline', 'reason' => 'A prescription is needed'])->assertOk();
    expect($askInsulin->fresh()->only(['status', 'decline_reason']))->toBe(['status' => 'declined', 'decline_reason' => 'A prescription is needed']);
    expect($this->getJson('/office-api/online/whatsapp')->json('total'))->toBe(0);
});
