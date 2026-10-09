<?php

namespace App\Domain\Channels\Connectors;

use App\Domain\Support\DomainException;
use App\Models\ChannelProduct;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\SalesOrder;
use App\Support\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * WooCommerce REST API v3 (/wp-json/wc/v3), HTTPS with a consumer key/secret (Read/Write).
 * Webhooks: X-WC-Webhook-Signature = base64(HMAC-SHA256(raw body, webhook secret));
 * X-WC-Webhook-Delivery-ID deduplicates; X-WC-Webhook-Topic e.g. order.created.
 * Contract tests: tests/Feature/WooCommerceConnectorTest.php with recorded payloads.
 */
final class WooCommerceConnector implements ChannelConnector
{
    public function capabilities(): array
    {
        return ['push_products', 'push_stock', 'push_prices', 'order_webhooks', 'order_status', 'reconcile'];
    }

    public function credentialFields(): array
    {
        return ['consumer_key' => 'Consumer key (ck_…)', 'consumer_secret' => 'Consumer secret (cs_…)'];
    }

    public function pushProduct(SalesChannel $channel, Product $product, ChannelProduct $mapping, array $state): array
    {
        $body = [
            'name' => $product->name,
            'sku' => $product->sku,
            'status' => $product->is_active ? 'publish' : 'draft',
            'manage_stock' => $product->track_stock,
            'stock_quantity' => $product->track_stock ? max(0, $state['available']) : null,
            'regular_price' => $state['price_minor'] === null ? '' : Money::format($state['price_minor'], $channel->priceList->currency_code),
            'catalog_visibility' => $state['price_minor'] === null ? 'hidden' : 'visible', // no price on this list = not for sale there
            'meta_data' => [['key' => '_erp_product_uom_id', 'value' => $mapping->product_uom_id]],
        ];
        $response = $mapping->external_id
            ? $this->client($channel)->put("products/{$mapping->external_id}", $body)
            : $this->client($channel)->post('products', $body);
        $this->ensureOk($response, 'push product '.$product->sku);

        return ['external_id' => (string) $response->json('id'), 'external_variant_id' => null];
    }

    public function pushOrderStatus(SalesChannel $channel, SalesOrder $order): void
    {
        $status = ['confirmed' => 'processing', 'ready' => 'processing', 'fulfilled' => 'completed', 'cancelled' => 'cancelled'][$order->status] ?? null;
        if ($status) {
            $this->ensureOk($this->client($channel)->put("orders/{$order->external_id}", ['status' => $status]), "update order {$order->number}");
        }
    }

    public function verifyWebhook(SalesChannel $channel, Request $request): bool
    {
        $given = (string) $request->header('X-WC-Webhook-Signature');
        $secret = (string) $channel->webhook_secret;

        return $given !== '' && $secret !== '' && hash_equals(base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true)), $given);
    }

    public function webhookMeta(Request $request): array
    {
        return ['delivery_id' => (string) $request->header('X-WC-Webhook-Delivery-ID'), 'topic' => (string) $request->header('X-WC-Webhook-Topic')];
    }

    public function normaliseOrder(SalesChannel $channel, string $topic, array $o): ?array
    {
        if (! in_array($topic, ['order.created', 'order.updated'], true) || ! in_array($o['status'] ?? '', ['processing', 'on-hold', 'pending', 'completed'], true)) {
            return null;
        }
        $currency = $channel->priceList->currency_code;
        $shipping = $o['shipping'] ?? [];
        $billing = $o['billing'] ?? [];

        return [
            'external_id' => (string) $o['id'],
            'number' => 'WC-'.($o['number'] ?? $o['id']),
            'placed_at' => $o['date_created_gmt'] ?? null ? $o['date_created_gmt'].'Z' : null,
            'customer' => ['name' => trim(($billing['first_name'] ?? '').' '.($billing['last_name'] ?? '')) ?: 'WooCommerce customer',
                'phone' => $billing['phone'] ?? null, 'email' => $billing['email'] ?? null],
            'fulfilment' => empty($o['shipping_lines']) ? 'pickup' : 'delivery',
            'address' => array_filter($shipping) ?: null,
            'payment_status' => ! empty($o['date_paid_gmt']) ? 'paid' : 'unpaid',
            'payment_method' => ! empty($o['date_paid_gmt']) ? 'online' : null,
            'payment_reference' => $o['transaction_id'] ?? null ?: null,
            'note' => $o['customer_note'] ?? null ?: null,
            'total_minor' => Money::toMinor((string) $o['total'], $currency),
            'lines' => array_map(fn ($l) => [
                'external_id' => (string) $l['product_id'],
                'external_variant_id' => ! empty($l['variation_id']) ? (string) $l['variation_id'] : null,
                'sku' => $l['sku'] ?? null,
                'qty' => (string) $l['quantity'],
                // Woo `price` is the unit price before tax; channel lists are tax-inclusive, so use total incl. tax / qty.
                'unit_price_minor' => intdiv(Money::toMinor((string) $l['total'], $currency) + Money::toMinor((string) ($l['total_tax'] ?? '0'), $currency), max(1, (int) $l['quantity'])),
            ], $o['line_items'] ?? []),
        ];
    }

    public function remoteState(SalesChannel $channel): array
    {
        $out = [];
        for ($page = 1; $page <= 50; $page++) {
            $r = $this->client($channel)->get('products', ['per_page' => 100, 'page' => $page]);
            $this->ensureOk($r, 'list products');
            foreach ($r->json() as $p) {
                $out[(string) $p['id']] = [
                    'price_minor' => ($p['regular_price'] ?? '') === '' ? null : Money::toMinor((string) $p['regular_price'], $channel->priceList->currency_code),
                    'stock' => $p['stock_quantity'] ?? null,
                ];
            }
            if ($page >= (int) $r->header('X-WP-TotalPages')) {
                break;
            }
        }

        return $out;
    }

    private function client(SalesChannel $channel): PendingRequest
    {
        $c = $channel->credentials ?? [];
        if (! str_starts_with((string) $channel->base_url, 'https://') && ! app()->environment('testing')) {
            throw new DomainException('WooCommerce must be reached over HTTPS.', 'insecure_url');
        }

        return Http::baseUrl(rtrim((string) $channel->base_url, '/').'/wp-json/wc/v3/')
            ->withBasicAuth((string) ($c['consumer_key'] ?? ''), (string) ($c['consumer_secret'] ?? ''))
            ->acceptJson()->timeout(30)->connectTimeout(10);
    }

    private function ensureOk($response, string $what): void
    {
        if ($response->status() === 429 || $response->serverError()) {
            throw new DomainException("WooCommerce is busy or failing ({$response->status()}) — will retry: {$what}", 'retryable');
        }
        if (! $response->successful()) {
            throw new DomainException("WooCommerce refused to {$what}: ".mb_substr((string) ($response->json('message') ?? $response->body()), 0, 300), 'refused');
        }
    }
}
