<?php

use App\Domain\Access\AccessService;
use App\Domain\Catalog\CatalogService;
use App\Domain\Channels\ChannelAdmin;
use App\Domain\Channels\ChannelSync;
use App\Domain\Channels\Connectors\ConnectorRegistry;
use App\Domain\Channels\LiveRelay;
use App\Domain\Channels\WebhookIntake;
use App\Domain\Devices\DeviceService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Support\DomainException;
use App\Events\LiveUpdate;
use App\Models\ChannelProduct;
use App\Models\PriceList;
use App\Models\SalesChannel;
use App\Models\SalesOrder;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    enableModule($this->owner, $this->branches['HQ'], 'storefront');
    $this->wh = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->wh, $this->milo, $this->milo->units->first(), '20', 'opening', '');
    $this->retail = PriceList::where('code', 'RETAIL')->first();
});

function makeChannel($t, string $type, array $secrets = []): SalesChannel
{
    $c = app(ChannelAdmin::class)->save(actorFor($t->owner), new SalesChannel, [
        'type' => $type, 'name' => ucfirst($type), 'branch_id' => $t->branches['HQ']->id, 'warehouse_id' => $t->wh->id,
        'price_list_id' => $t->retail->id, 'safety_stock' => '0', 'base_url' => $type === 'storefront' ? null : 'https://acme.myshopify.test',
    ], $secrets);

    return app(ChannelAdmin::class)->activate(actorFor($t->owner), $c);
}

it('Shopify: pushes products with productSet and stock with inventorySetQuantities', function () {
    Http::fake(['https://acme.myshopify.test/admin/api/*' => Http::sequence()
        ->push(['data' => ['productSet' => ['product' => ['id' => 'gid://shopify/Product/1', 'variants' => ['nodes' => [
            ['id' => 'gid://shopify/ProductVariant/11', 'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/111']]]]], 'userErrors' => []]]])
        ->push(['data' => ['inventorySetQuantities' => ['userErrors' => []]]])]);
    $c = makeChannel($this, 'shopify', ['access_token' => 'shpat_test', 'location_id' => 'gid://shopify/Location/9', 'webhook_secret' => 'shp_secret']);

    app(ChannelSync::class)->runDue($c);

    Http::assertSent(fn (Request $r) => str_contains($r['query'], 'productSet') && $r['variables']['input']['variants'][0]['price'] === '58.00'
        && $r->hasHeader('X-Shopify-Access-Token', 'shpat_test'));
    Http::assertSent(fn (Request $r) => str_contains($r['query'], 'inventorySetQuantities') && $r['variables']['input']['quantities'][0]['quantity'] === 20);
    expect(ChannelProduct::first()->external_variant_id)->toBe('gid://shopify/ProductVariant/11');
});

it('Shopify: verifies X-Shopify-Hmac-Sha256 and ingests orders/create', function () {
    $c = makeChannel($this, 'shopify', ['access_token' => 'shpat_test', 'location_id' => 'gid://shopify/Location/9', 'webhook_secret' => 'shp_secret']);
    ChannelProduct::create(['tenant_id' => $this->tenant->id, 'channel_id' => $c->id, 'product_id' => $this->milo->id,
        'product_uom_id' => $this->milo->units->first()->id, 'external_id' => 'gid://shopify/Product/1', 'external_variant_id' => 'gid://shopify/ProductVariant/11']);
    $raw = json_encode(['id' => 4501, 'admin_graphql_api_id' => 'gid://shopify/Order/4501', 'order_number' => 1001, 'financial_status' => 'paid',
        'total_price' => '58.00', 'email' => 'ama@example.test', 'customer' => ['first_name' => 'Ama', 'last_name' => 'Boateng'],
        'line_items' => [['variant_id' => 11, 'sku' => 'MILO', 'quantity' => 1, 'price' => '58.00']]]);
    $send = fn ($sig) => $this->call('POST', "/api/v1/channels/{$c->id}/webhooks", [], [], [], ['HTTP_X_SHOPIFY_HMAC_SHA256' => $sig,
        'HTTP_X_SHOPIFY_TOPIC' => 'orders/create', 'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'w-1', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $raw);

    $send('bad')->assertUnauthorized();
    $send(base64_encode(hash_hmac('sha256', $raw, 'shp_secret', true)))->assertOk();
    app(WebhookIntake::class)->process($c->fresh());

    expect(SalesOrder::first()?->number)->toBe('SH-1001')->and(SalesOrder::first()->customer_name)->toBe('Ama Boateng');
});

it('lists marketplaces as planned and will not let them be set up', function () {
    expect(ConnectorRegistry::availableOptions())->not->toHaveKey('jumia');
    makeChannel($this, 'jumia');
})->throws(DomainException::class, 'not available yet');

it('tells tills and storefronts about a price change as soon as it commits', function () {
    $shop = makeChannel($this, 'storefront');
    app(LiveRelay::class)->run(); // consumer starts from "now"
    Event::fake([LiveUpdate::class]);

    app(CatalogService::class)->saveProduct(actorFor($this->owner), $this->milo, ['prices' => [
        ['price_list_id' => $this->retail->id, 'uom_id' => $this->milo->units->first()->uom_id, 'min_qty' => '1', 'amount' => '61.50'],
    ]]);
    app(LiveRelay::class)->run();

    Event::assertDispatched(LiveUpdate::class, fn (LiveUpdate $e) => $e->channel === "tenant.{$this->tenant->id}.catalog" && $e->private
        && in_array($this->milo->id, $e->data['product_ids'], true));
    Event::assertDispatched(LiveUpdate::class, fn (LiveUpdate $e) => $e->channel === "storefront.{$shop->id}" && ! $e->private
        && $e->name === 'product.updated' && $e->data['price_minor'] === 6150 && $e->data['available'] === 20);
});

it('lets a signed-in till listen to its own company only', function () {
    $till = Till::where('branch_id', $this->branches['HQ']->id)->first();
    [$device, $code] = app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $till->branch_id, 'till_id' => $till->id, 'name' => 'T', 'kind' => 'pos']);
    $deviceToken = $this->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->json('token');
    $cashier = staff($this->owner, 'esi', 2, 'teller', ScopeType::Till, $till->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $cashier, '482613');
    app('auth')->forgetGuards();
    $token = $this->withToken($deviceToken)->postJson('/api/v1/pos/login', ['username' => 'esi', 'pin' => '482613'])->json('token');
    app('auth')->forgetGuards();
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.secret' => 's',
        'broadcasting.connections.reverb.app_id' => '1']);
    require base_path('routes/channels.php'); // channel rules were registered on the test's null broadcaster at boot

    // The till is told the public https address; the server's own publishing target (same PC) is never handed out.
    config(['broadcasting.connections.reverb.client' => ['host' => 'shop.test', 'port' => 443, 'scheme' => 'https'],
        'broadcasting.connections.reverb.options.host' => '127.0.0.1', 'broadcasting.connections.reverb.options.port' => 8080]);
    $this->withToken($token)->getJson('/api/v1/pos/bootstrap')->assertOk()
        ->assertJsonPath('realtime.host', 'shop.test')->assertJsonPath('realtime.port', 443)->assertJsonPath('realtime.scheme', 'https');
    app('auth')->forgetGuards();

    $this->withToken($token)->postJson('/api/v1/pos/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => "private-tenant.{$this->tenant->id}.catalog"])
        ->assertOk()->assertJsonStructure(['auth']);
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/v1/pos/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-tenant.00000000-0000-0000-0000-000000000000.catalog'])
        ->assertForbidden();
});
