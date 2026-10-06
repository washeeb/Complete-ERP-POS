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

/**
 * Shopify Admin GraphQL API (custom app access token). Products via productSet (create or update by
 * id, idempotent), stock via inventorySetQuantities at the configured location.
 * Webhooks: X-Shopify-Hmac-Sha256 = base64(HMAC-SHA256(raw body, app secret)); X-Shopify-Webhook-Id dedupes.
 */
final class ShopifyConnector implements ChannelConnector
{
    public const API_VERSION = '2026-07';

    public function capabilities(): array
    {
        return ['push_products', 'push_stock', 'push_prices', 'order_webhooks', 'order_status', 'reconcile'];
    }

    public function credentialFields(): array
    {
        return ['access_token' => 'Admin API access token (shpat_…)', 'location_id' => 'Location ID (gid://shopify/Location/…)'];
    }

    public function pushProduct(SalesChannel $channel, Product $product, ChannelProduct $mapping, array $state): array
    {
        $variant = [
            'sku' => $product->sku,
            'price' => $state['price_minor'] === null ? '0.00' : Money::format($state['price_minor'], $channel->priceList->currency_code),
            'optionValues' => [['optionName' => 'Title', 'name' => 'Default Title']],
            'inventoryItem' => ['tracked' => $product->track_stock],
        ];
        if ($mapping->external_variant_id) {
            $variant['id'] = $mapping->external_variant_id;
        }
        $input = [
            'title' => $product->name,
            'status' => $product->is_active && $state['price_minor'] !== null ? 'ACTIVE' : 'DRAFT',
            'productOptions' => [['name' => 'Title', 'values' => [['name' => 'Default Title']]]],
            'variants' => [$variant],
        ];
        if ($mapping->external_id) {
            $input['id'] = $mapping->external_id;
        }
        $data = $this->gql($channel, 'mutation ($input: ProductSetInput!) { productSet(synchronous: true, input: $input) {
            product { id variants(first: 1) { nodes { id inventoryItem { id } } } } userErrors { field message } } }', ['input' => $input]);
        $this->userErrors($data['productSet']['userErrors'] ?? [], 'push product '.$product->sku);
        $productId = $data['productSet']['product']['id'];
        $v = $data['productSet']['product']['variants']['nodes'][0];

        if ($product->track_stock) {
            $loc = (string) ($channel->credentials['location_id'] ?? '');
            $inv = $this->gql($channel, 'mutation ($input: InventorySetQuantitiesInput!) { inventorySetQuantities(input: $input) { userErrors { field message } } }', [
                'input' => ['name' => 'available', 'reason' => 'correction', 'ignoreCompareQuantity' => true,
                    'quantities' => [['inventoryItemId' => $v['inventoryItem']['id'], 'locationId' => $loc, 'quantity' => max(0, $state['available'])]]],
            ]);
            $this->userErrors($inv['inventorySetQuantities']['userErrors'] ?? [], 'set stock for '.$product->sku);
        }

        return ['external_id' => $productId, 'external_variant_id' => $v['id']];
    }

    public function pushOrderStatus(SalesChannel $channel, SalesOrder $order): void
    {
        if ($order->status === 'cancelled') {
            $d = $this->gql($channel, 'mutation ($id: ID!) { orderCancel(orderId: $id, reason: OTHER, refund: false, restock: false, notifyCustomer: true) { userErrors { field message } } }',
                ['id' => $order->external_id]);
            $this->userErrors($d['orderCancel']['userErrors'] ?? [], 'cancel order '.$order->number);
        }
        // Fulfilment in Shopify is created by the merchant's fulfilment flow; marking it here would be guessing (capability gap, documented).
    }

    public function verifyWebhook(SalesChannel $channel, Request $request): bool
    {
        $given = (string) $request->header('X-Shopify-Hmac-Sha256');
        $secret = (string) $channel->webhook_secret;

        return $given !== '' && $secret !== '' && hash_equals(base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true)), $given);
    }

    public function webhookMeta(Request $request): array
    {
        return ['delivery_id' => (string) $request->header('X-Shopify-Webhook-Id'), 'topic' => (string) $request->header('X-Shopify-Topic')];
    }

    public function normaliseOrder(SalesChannel $channel, string $topic, array $o): ?array
    {
        if ($topic !== 'orders/create') {
            return null;
        }
        $currency = $channel->priceList->currency_code;
        $c = $o['customer'] ?? [];

        return [
            'external_id' => (string) ($o['admin_graphql_api_id'] ?? 'gid://shopify/Order/'.$o['id']),
            'number' => 'SH-'.($o['order_number'] ?? $o['id']),
            'placed_at' => $o['created_at'] ?? null,
            'customer' => ['name' => trim(($c['first_name'] ?? '').' '.($c['last_name'] ?? '')) ?: 'Shopify customer',
                'phone' => $o['phone'] ?? ($c['phone'] ?? null), 'email' => $o['email'] ?? null],
            'fulfilment' => empty($o['shipping_lines']) ? 'pickup' : 'delivery',
            'address' => $o['shipping_address'] ?? null,
            'payment_status' => ($o['financial_status'] ?? '') === 'paid' ? 'paid' : 'unpaid',
            'payment_method' => ($o['financial_status'] ?? '') === 'paid' ? 'online' : null,
            'total_minor' => Money::toMinor((string) $o['total_price'], $currency),
            'lines' => array_map(fn ($l) => [
                'external_variant_id' => isset($l['variant_id']) ? 'gid://shopify/ProductVariant/'.$l['variant_id'] : null,
                'sku' => $l['sku'] ?? null,
                'qty' => (string) $l['quantity'],
                'unit_price_minor' => Money::toMinor((string) $l['price'], $currency),
            ], $o['line_items'] ?? []),
        ];
    }

    public function remoteState(SalesChannel $channel): array
    {
        $out = [];
        $after = null;
        for ($i = 0; $i < 50; $i++) {
            $d = $this->gql($channel, 'query ($after: String) { productVariants(first: 250, after: $after) { pageInfo { hasNextPage endCursor }
                nodes { id price inventoryQuantity } } }', ['after' => $after]);
            foreach ($d['productVariants']['nodes'] as $v) {
                $out[$v['id']] = ['price_minor' => Money::toMinor((string) $v['price'], $channel->priceList->currency_code), 'stock' => $v['inventoryQuantity']];
            }
            if (! $d['productVariants']['pageInfo']['hasNextPage']) {
                break;
            }
            $after = $d['productVariants']['pageInfo']['endCursor'];
        }

        return $out;
    }

    private function gql(SalesChannel $channel, string $query, array $variables): array
    {
        $r = Http::withHeaders(['X-Shopify-Access-Token' => (string) ($channel->credentials['access_token'] ?? '')])
            ->acceptJson()->timeout(30)
            ->post(rtrim((string) $channel->base_url, '/').'/admin/api/'.self::API_VERSION.'/graphql.json', ['query' => $query, 'variables' => $variables]);
        if ($r->status() === 429 || $r->serverError() || $r->json('errors.0.extensions.code') === 'THROTTLED') {
            throw new DomainException("Shopify is busy ({$r->status()}) — will retry", 'retryable');
        }
        if (! $r->successful() || $r->json('errors')) {
            throw new DomainException('Shopify refused the request: '.mb_substr(json_encode($r->json('errors') ?? $r->body()), 0, 300), 'refused');
        }

        return $r->json('data');
    }

    private function userErrors(array $errors, string $what): void
    {
        if ($errors) {
            throw new DomainException("Shopify refused to {$what}: ".implode('; ', array_column($errors, 'message')), 'refused');
        }
    }
}
