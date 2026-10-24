<?php

namespace App\Domain\Channels\Connectors;

use App\Models\ChannelProduct;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\SalesOrder;
use Illuminate\Http\Request;

/**
 * Orders by WhatsApp (owner request 2026-10-24): staff turn a customer's message into an order on this channel (one per
 * branch, made the first time). Orders in only: nothing is pushed to WhatsApp, and messages come in through
 * WhatsappOrders, not the channel webhook.
 */
final class WhatsappConnector implements ChannelConnector
{
    public function capabilities(): array
    {
        return ['orders'];
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
        return false;
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
