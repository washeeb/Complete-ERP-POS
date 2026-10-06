<?php

namespace App\Domain\Channels\Connectors;

use App\Domain\Support\DomainException;
use App\Models\ChannelProduct;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\SalesOrder;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Generic connector for a custom site or middleware. We POST JSON to {base_url}/products and
 * {base_url}/orders/{id}/status; they POST orders to our webhook URL. Both directions are signed:
 * X-Synclytics-Signature = hex HMAC-SHA256(secret, timestamp + "." + raw body), X-Synclytics-Timestamp
 * within 5 minutes, X-Synclytics-Event-Id for replay protection (docs/api.md).
 */
final class RestConnector implements ChannelConnector
{
    public function capabilities(): array
    {
        return ['push_products', 'push_stock', 'push_prices', 'order_webhooks', 'order_status'];
    }

    public function credentialFields(): array
    {
        return [];
    }

    public function pushProduct(SalesChannel $channel, Product $product, ChannelProduct $mapping, array $state): array
    {
        $r = $this->send($channel, 'products', [
            'product_uom_id' => $mapping->product_uom_id, 'sku' => $product->sku, 'name' => $product->name, 'active' => (bool) $product->is_active,
            'price' => $state['price_minor'] === null ? null : Money::format($state['price_minor'], $channel->priceList->currency_code),
            'currency' => $channel->priceList->currency_code, 'available' => $product->track_stock ? max(0, $state['available']) : null,
        ]);

        return ['external_id' => (string) ($r['id'] ?? $mapping->product_uom_id), 'external_variant_id' => null];
    }

    public function pushOrderStatus(SalesChannel $channel, SalesOrder $order): void
    {
        $this->send($channel, "orders/{$order->external_id}/status", ['status' => $order->status, 'payment_status' => $order->payment_status]);
    }

    public function verifyWebhook(SalesChannel $channel, Request $request): bool
    {
        $ts = (string) $request->header('X-Synclytics-Timestamp');
        $given = (string) $request->header('X-Synclytics-Signature');
        if (! ctype_digit($ts) || abs(time() - (int) $ts) > 300 || $given === '' || ! $channel->webhook_secret) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $ts.'.'.$request->getContent(), (string) $channel->webhook_secret), $given);
    }

    public function webhookMeta(Request $request): array
    {
        return ['delivery_id' => (string) $request->header('X-Synclytics-Event-Id'), 'topic' => (string) ($request->json('topic') ?? 'order.created')];
    }

    public function normaliseOrder(SalesChannel $channel, string $topic, array $p): ?array
    {
        if ($topic !== 'order.created') {
            return null;
        }
        $o = $p['order'] ?? $p;
        $currency = $channel->priceList->currency_code;

        return [
            'external_id' => (string) $o['id'], 'number' => (string) ($o['number'] ?? $o['id']), 'placed_at' => $o['placed_at'] ?? null,
            'customer' => ['name' => (string) ($o['customer']['name'] ?? 'Online customer'), 'phone' => $o['customer']['phone'] ?? null, 'email' => $o['customer']['email'] ?? null],
            'fulfilment' => $o['fulfilment'] ?? 'pickup', 'address' => $o['address'] ?? null,
            'payment_status' => $o['payment_status'] ?? 'unpaid', 'payment_method' => $o['payment_method'] ?? null, 'payment_reference' => $o['payment_reference'] ?? null,
            'total_minor' => isset($o['total']) ? Money::toMinor((string) $o['total'], $currency) : null,
            'lines' => array_map(fn ($l) => ['product_uom_id' => $l['product_uom_id'] ?? null, 'sku' => $l['sku'] ?? null, 'qty' => (string) $l['qty'],
                'unit_price_minor' => Money::toMinor((string) $l['price'], $currency)], $o['lines'] ?? []),
        ];
    }

    public function remoteState(SalesChannel $channel): array
    {
        return []; // capability not offered (no 'reconcile')
    }

    private function send(SalesChannel $channel, string $path, array $body): array
    {
        $raw = json_encode($body);
        $ts = (string) time();
        $r = Http::withHeaders([
            'X-Synclytics-Timestamp' => $ts,
            'X-Synclytics-Event-Id' => (string) Str::uuid7(),
            'X-Synclytics-Signature' => hash_hmac('sha256', $ts.'.'.$raw, (string) $channel->webhook_secret),
        ])->withBody($raw, 'application/json')->timeout(20)->post(rtrim((string) $channel->base_url, '/').'/'.$path);
        if ($r->status() === 429 || $r->serverError()) {
            throw new DomainException("{$channel->name} is busy ({$r->status()}) — will retry", 'retryable');
        }
        if (! $r->successful()) {
            throw new DomainException("{$channel->name} refused {$path}: ".mb_substr($r->body(), 0, 300), 'refused');
        }

        return (array) $r->json();
    }
}
