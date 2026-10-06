<?php

namespace App\Domain\Channels\Connectors;

use App\Models\ChannelProduct;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\SalesOrder;
use Illuminate\Http\Request;

/**
 * The built-in shop reads the catalog straight from this server and places orders directly, so
 * there is nothing to push and no webhook to verify. Live price/stock changes reach shoppers'
 * browsers through Reverb.
 */
final class StorefrontConnector implements ChannelConnector
{
    public function capabilities(): array
    {
        return ['live_catalog', 'orders'];
    }

    public function credentialFields(): array
    {
        return [];
    }

    public function pushProduct(SalesChannel $channel, Product $product, ChannelProduct $mapping, array $state): array
    {
        return ['external_id' => $product->id, 'external_variant_id' => $mapping->product_uom_id];
    }

    public function pushOrderStatus(SalesChannel $channel, SalesOrder $order): void {}

    public function verifyWebhook(SalesChannel $channel, Request $request): bool
    {
        return false; // the storefront never calls in through webhooks
    }

    public function webhookMeta(Request $request): array
    {
        return ['delivery_id' => '', 'topic' => ''];
    }

    public function normaliseOrder(SalesChannel $channel, string $topic, array $payload): ?array
    {
        return null;
    }

    public function remoteState(SalesChannel $channel): array
    {
        return [];
    }
}
