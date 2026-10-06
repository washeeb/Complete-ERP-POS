<?php

namespace App\Domain\Channels\Connectors;

use App\Models\ChannelProduct;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\SalesOrder;
use Illuminate\Http\Request;

/**
 * What every store adapter does. Capability gaps are explicit (capabilities()), never silently emulated.
 */
interface ChannelConnector
{
    /** @return list<string> e.g. ['push_products', 'push_stock', 'push_prices', 'order_webhooks', 'order_status', 'reconcile'] */
    public function capabilities(): array;

    /** Credentials fields the admin must enter: name => label. */
    public function credentialFields(): array;

    /**
     * Create or update the product on the store (name, SKU, price on the channel's list, available stock).
     *
     * @param  array{price_minor: ?int, available: int}  $state  available in the unit, whole units
     * @return array{external_id: string, external_variant_id: ?string}
     */
    public function pushProduct(SalesChannel $channel, Product $product, ChannelProduct $mapping, array $state): array;

    public function pushOrderStatus(SalesChannel $channel, SalesOrder $order): void;

    /** Constant-time check of the provider's signature over the raw body, before any parsing. */
    public function verifyWebhook(SalesChannel $channel, Request $request): bool;

    /** @return array{delivery_id: string, topic: string} */
    public function webhookMeta(Request $request): array;

    /** Provider order JSON → OnlineOrderService input, or null when the topic is not an order to ingest. */
    public function normaliseOrder(SalesChannel $channel, string $topic, array $payload): ?array;

    /** @return array<string, array{price_minor: ?int, stock: ?int}> keyed by external (variant) id */
    public function remoteState(SalesChannel $channel): array;
}
