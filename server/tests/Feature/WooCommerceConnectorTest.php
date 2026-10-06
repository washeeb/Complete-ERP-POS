<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Channels\ChannelAdmin;
use App\Domain\Channels\ChannelSync;
use App\Domain\Channels\OnlineOrderService;
use App\Domain\Channels\Reconciler;
use App\Domain\Channels\WebhookIntake;
use App\Domain\Inventory\InventoryService;
use App\Domain\Support\DomainException;
use App\Models\ChannelJob;
use App\Models\ChannelProduct;
use App\Models\PriceList;
use App\Models\ReviewItem;
use App\Models\SalesChannel;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Models\WebhookInboxEntry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const WC = 'https://shop.example.test/wp-json/wc/v3/';
const WC_SECRET = 'whsec_test_only';

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->wh = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->wh, $this->milo, $this->milo->units->first(), '20', 'opening', '');
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $this->channel = app(ChannelAdmin::class)->save(actorFor($this->owner), new SalesChannel, [
        'type' => 'woocommerce', 'name' => 'Woo shop', 'branch_id' => $this->branches['HQ']->id, 'warehouse_id' => $this->wh->id,
        'price_list_id' => $this->retail->id, 'safety_stock' => '3', 'base_url' => 'https://shop.example.test',
    ], ['consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test', 'webhook_secret' => WC_SECRET]);
    $this->sync = app(ChannelSync::class);
});

function runChannel($t): array
{
    $t->sync->dispatch($t->channel->fresh());

    return $t->sync->runDue($t->channel->fresh());
}

function wcWebhook($t, array $order, string $topic = 'order.created', ?string $sig = null, string $delivery = 'd-1')
{
    $raw = json_encode($order);

    return $t->call('POST', "/api/v1/channels/{$t->channel->id}/webhooks", [], [], [], [
        'HTTP_X_WC_WEBHOOK_SIGNATURE' => $sig ?? base64_encode(hash_hmac('sha256', $raw, WC_SECRET, true)),
        'HTTP_X_WC_WEBHOOK_TOPIC' => $topic, 'HTTP_X_WC_WEBHOOK_DELIVERY_ID' => $delivery,
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    ], $raw);
}

/** Shape of a WooCommerce v3 order (fields we read), as WooCommerce sends it. */
function wcOrder(array $over = []): array
{
    return array_replace_recursive([
        'id' => 727, 'number' => '727', 'status' => 'processing', 'currency' => 'GHS', 'date_created_gmt' => '2026-10-06T09:00:00',
        'date_paid_gmt' => '2026-10-06T09:01:00', 'transaction_id' => 'pst_123', 'total' => '116.00', 'customer_note' => '',
        'billing' => ['first_name' => 'Kwame', 'last_name' => 'Asante', 'phone' => '0201234567', 'email' => 'kwame@example.test'],
        'shipping' => [], 'shipping_lines' => [],
        'line_items' => [['product_id' => 5501, 'variation_id' => 0, 'sku' => 'MILO', 'quantity' => 2, 'total' => '96.67', 'total_tax' => '19.33']],
    ], $over);
}

it('refuses to activate without credentials and webhook secret', function () {
    $c = app(ChannelAdmin::class)->save(actorFor($this->owner), new SalesChannel, [
        'type' => 'woocommerce', 'name' => 'Bare', 'branch_id' => $this->branches['HQ']->id, 'warehouse_id' => $this->wh->id,
        'price_list_id' => $this->retail->id, 'base_url' => 'https://x.test',
    ]);
    app(ChannelAdmin::class)->activate(actorFor($this->owner), $c);
})->throws(DomainException::class, 'Consumer key');

it('pushes the catalog on activation with the channel price and stock minus safety stock', function () {
    Http::fake([WC.'products' => Http::response(['id' => 5501], 201)]);
    app(ChannelAdmin::class)->activate(actorFor($this->owner), $this->channel);

    runChannel($this);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === WC.'products'
        && $r['sku'] === 'MILO' && $r['regular_price'] === '58.00' && $r['stock_quantity'] === 17 // 20 − 3 safety
        && $r->hasHeader('Authorization', 'Basic '.base64_encode('ck_test:cs_test')));
    expect(ChannelProduct::first()->external_id)->toBe('5501');
});

it('sends a price change to the store at the next relay pass, and skips pushes that change nothing', function () {
    Http::fake([WC.'products' => Http::response(['id' => 5501], 201), WC.'products/5501' => Http::response(['id' => 5501])]);
    app(ChannelAdmin::class)->activate(actorFor($this->owner), $this->channel);
    runChannel($this);

    app(CatalogService::class)->saveProduct(actorFor($this->owner), $this->milo, ['prices' => [
        ['price_list_id' => $this->retail->id, 'uom_id' => $this->milo->units->first()->uom_id, 'min_qty' => '1', 'amount' => '61.50'],
    ]]);
    runChannel($this);
    runChannel($this); // nothing new

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === WC.'products/5501' && $r['regular_price'] === '61.50');
    Http::assertSentCount(2);
});

it('accepts a correctly signed order webhook once, and turns it into a reserved order', function () {
    app(ChannelAdmin::class)->activate(actorFor($this->owner), $this->channel);
    ChannelProduct::create(['tenant_id' => $this->tenant->id, 'channel_id' => $this->channel->id, 'product_id' => $this->milo->id,
        'product_uom_id' => $this->milo->units->first()->id, 'external_id' => '5501']);

    wcWebhook($this, wcOrder())->assertOk()->assertJson(['status' => 'received']);
    wcWebhook($this, wcOrder())->assertOk()->assertJson(['status' => 'duplicate']);
    app(WebhookIntake::class)->process($this->channel->fresh());

    $o = SalesOrder::first();
    expect(WebhookInboxEntry::count())->toBe(1)
        ->and($o->number)->toBe('WC-727')->and($o->payment_status)->toBe('paid')->and($o->payment_reference)->toBe('pst_123')
        ->and($o->total_minor)->toBe(11600)->and($o->lines->first()->qty)->toBe('2.000')
        ->and(ReviewItem::where('type', 'channel_total')->exists())->toBeFalse();
});

it('rejects a webhook whose signature does not match the raw body', function () {
    app(ChannelAdmin::class)->activate(actorFor($this->owner), $this->channel);

    wcWebhook($this, wcOrder(), sig: base64_encode(random_bytes(32)))->assertUnauthorized()->assertJson(['error' => 'bad_signature']);
    expect(WebhookInboxEntry::count())->toBe(0);
});

it('ignores topics and statuses that are not new orders', function () {
    app(ChannelAdmin::class)->activate(actorFor($this->owner), $this->channel);
    wcWebhook($this, wcOrder(['status' => 'cancelled']), delivery: 'd-2')->assertOk();
    app(WebhookIntake::class)->process($this->channel->fresh());

    expect(WebhookInboxEntry::first()->status)->toBe('ignored')->and(SalesOrder::count())->toBe(0);
});

it('tells the store when an order is fulfilled', function () {
    Http::fake([WC.'*' => Http::response(['id' => 1])]);
    app(ChannelAdmin::class)->activate(actorFor($this->owner), $this->channel);
    $o = app(OnlineOrderService::class)->ingest($this->channel->fresh(), ['external_id' => '727', 'customer' => ['name' => 'K'],
        'payment_status' => 'paid', 'lines' => [['sku' => 'MILO', 'qty' => '1', 'unit_price_minor' => 5800]]])['order'];
    runChannel($this);
    app(OnlineOrderService::class)->fulfil(actorFor($this->owner), $o->fresh());
    runChannel($this);

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === WC.'orders/727' && $r['status'] === 'completed');
});

it('retries a failing store with backoff and dead-letters it after repeated failure', function () {
    Http::fake([WC.'products' => Http::response(['message' => 'down'], 503)]);
    app(ChannelAdmin::class)->activate(actorFor($this->owner), $this->channel);

    runChannel($this);
    $job = ChannelJob::where('kind', 'product')->first();
    expect($job->status)->toBe('pending')->and($job->attempts)->toBe(1)->and($job->next_attempt_at->isFuture())->toBeTrue();

    for ($i = 1; $i < ChannelSync::MAX_ATTEMPTS; $i++) {
        $job->forceFill(['next_attempt_at' => now()->subSecond()])->save();
        $this->sync->runDue($this->channel->fresh());
        $job->refresh();
    }
    expect($job->status)->toBe('dead')
        ->and(ReviewItem::where('type', 'channel_failed')->exists())->toBeTrue();
});

it('reconciles: finds a store price and stock that drifted, and re-pushes them', function () {
    Http::fake([
        WC.'products' => Http::sequence()->push(['id' => 5501], 201)
            ->push([['id' => 5501, 'regular_price' => '50.00', 'stock_quantity' => 99]], 200, ['X-WP-TotalPages' => '1']),
        WC.'products?*' => Http::response([['id' => 5501, 'regular_price' => '50.00', 'stock_quantity' => 99]], 200, ['X-WP-TotalPages' => '1']),
        WC.'products/5501' => Http::response(['id' => 5501]),
    ]);
    app(ChannelAdmin::class)->activate(actorFor($this->owner), $this->channel);
    runChannel($this);

    $diffs = app(Reconciler::class)->run($this->channel->fresh());

    expect(collect($diffs)->pluck('field')->all())->toBe(['price', 'stock'])
        ->and(ChannelJob::where('status', 'pending')->count())->toBe(1);
});
