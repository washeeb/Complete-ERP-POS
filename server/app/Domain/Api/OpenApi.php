<?php

namespace App\Domain\Api;

/** The OpenAPI 3.1 description of the public API (served at /api/public/v1/openapi.json). Kept next to the routes it describes. */
final class OpenApi
{
    /** path => [method => [scope, summary, kind]] — kind: list, item, create, delete */
    public const PATHS = [
        '/products' => ['get' => ['products:read', 'List products with units, barcodes and default prices', 'list']],
        '/products/{id}' => ['get' => ['products:read', 'One product', 'item']],
        '/stock' => ['get' => ['stock:read', 'Stock on hand per warehouse and product (filters: warehouse, product_id)', 'list']],
        '/customers' => ['get' => ['customers:read', 'List customers with balances (filter: search)', 'list'], 'post' => ['customers:write', 'Create a customer', 'create']],
        '/customers/{id}' => ['get' => ['customers:read', 'One customer', 'item']],
        '/sales' => ['get' => ['sales:read', 'Till sales and returns with lines (filters: from, to)', 'list']],
        '/invoices' => ['get' => ['invoices:read', 'Customer invoices (filter: customer_id)', 'list']],
        '/invoices/{id}' => ['get' => ['invoices:read', 'One invoice with lines', 'item']],
        '/orders' => ['post' => ['orders:write', 'Create a customer order: {customer_id, warehouse?, lines: [{sku, qty, unit?}], reference?, note?, confirm?}', 'create']],
        '/webhooks' => ['get' => ['webhooks:manage', "This key's webhooks", 'list'], 'post' => ['webhooks:manage', 'Add a webhook: {url, events}. The answer holds the signing secret (shown once).', 'create']],
        '/webhooks/{id}' => ['delete' => ['webhooks:manage', 'Remove a webhook', 'delete']],
    ];

    public static function document(): array
    {
        $paths = [];
        foreach (self::PATHS as $path => $methods) {
            foreach ($methods as $method => [$scope, $summary, $kind]) {
                $params = [];
                if (str_contains($path, '{id}')) {
                    $params[] = ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];
                }
                if ($kind === 'list') {
                    foreach (['limit' => 'Up to 200 (default 50)', 'after' => 'Continue after this id (the "next" of the previous page)', 'updated_since' => 'Only records changed since (ISO 8601)'] as $n => $d) {
                        $params[] = ['name' => $n, 'in' => 'query', 'required' => false, 'description' => $d, 'schema' => ['type' => 'string']];
                    }
                }
                $paths[$path][$method] = array_filter([
                    'summary' => $summary, 'security' => [['apiKey' => [$scope]]], 'parameters' => $params ?: null,
                    'requestBody' => $kind === 'create' ? ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object']]]] : null,
                    'responses' => [
                        ($kind === 'create' ? '201' : ($kind === 'delete' ? '204' : '200')) => ['description' => 'OK', 'content' => ['application/json' => ['schema' => $kind === 'list'
                            ? ['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => ['type' => 'object']], 'next' => ['type' => ['string', 'null']]]]
                            : ['type' => 'object', 'properties' => ['data' => ['type' => 'object']]]]]],
                        '401' => ['description' => 'Missing or revoked key'], '403' => ['description' => 'Scope or permission missing'],
                        '422' => ['description' => 'Refused by a business rule: {error, detail}'], '429' => ['description' => 'Too many requests (120 a minute per client)'],
                    ],
                ]);
            }
        }

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Synclytics ERP public API', 'version' => '1.0.0',
                'description' => "Keys are created under System → API clients. Send them as `Authorization: Bearer <key>`. A key carries scopes and acts as a person: it never sees more than that person may in the back office.\n\n"
                    .'Webhooks are POSTed as JSON {id, type, created_at, data} with headers X-Synclytics-Event, X-Synclytics-Delivery and X-Synclytics-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of "t.body" with the webhook secret>. Retries reuse the same id — de-duplicate on it. Events: '
                    .implode(', ', array_keys(ApiClients::EVENTS)).'.'],
            'servers' => [['url' => rtrim((string) config('app.url'), '/').'/api/public/v1']],
            'components' => ['securitySchemes' => ['apiKey' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Scopes: '.implode(', ', array_keys(ApiClients::SCOPES))]]],
            'paths' => $paths,
        ];
    }
}
