<?php

namespace App\Domain\Channels\Connectors;

use App\Domain\Support\DomainException;
use App\Models\ChannelProduct;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\SalesOrder;
use Illuminate\Http\Request;

/**
 * Delivery apps (Bolt Food, Glovo, Chowdeck): orders in only. They post to our webhook URL in the signed
 * format (as the generic REST channel, docs/api.md), directly or through the integration partner that bridges their
 * merchant API; nothing is pushed to them. Orders become delivery bills for the kitchen (Restaurant module).
 */
final class DeliveryConnector implements ChannelConnector
{
    public function capabilities(): array
    {
        return ['order_webhooks'];
    }

    public function credentialFields(): array
    {
        return [];
    }

    public function pushProduct(SalesChannel $channel, Product $product, ChannelProduct $mapping, array $state): array
    {
        throw new DomainException('Delivery apps receive no product updates from here.', 'refused');
    }

    public function pushOrderStatus(SalesChannel $channel, SalesOrder $order): void {}

    public function verifyWebhook(SalesChannel $channel, Request $request): bool
    {
        return app(RestConnector::class)->verifyWebhook($channel, $request);
    }

    public function webhookMeta(Request $request): array
    {
        return app(RestConnector::class)->webhookMeta($request);
    }

    public function normaliseOrder(SalesChannel $channel, string $topic, array $p): ?array
    {
        if ($topic !== 'order.created') {
            return null;
        }
        $o = $p['order'] ?? $p;
        if (empty($o['id']) || ! is_array($o['lines'] ?? null)) {
            throw new DomainException('Order without id or lines.', 'malformed');
        }

        return [
            'external_id' => (string) $o['id'], 'number' => (string) ($o['number'] ?? $o['id']),
            'customer' => ['name' => $o['customer']['name'] ?? null, 'phone' => $o['customer']['phone'] ?? null], 'note' => $o['note'] ?? null,
            'lines' => array_map(fn ($l) => ['sku' => $l['sku'] ?? null, 'product_uom_id' => $l['product_uom_id'] ?? null, 'qty' => (string) ($l['qty'] ?? '1'),
                'note' => $l['note'] ?? null], $o['lines']),
        ];
    }

    public function remoteState(SalesChannel $channel): array
    {
        return [];
    }
}
