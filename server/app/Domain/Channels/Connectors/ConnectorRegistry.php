<?php

namespace App\Domain\Channels\Connectors;

use App\Domain\Support\DomainException;

/**
 * Store and marketplace types. Marketplaces and delivery platforms are listed so the plan is
 * visible, but they cannot be activated until their connector exists (spec §5: stubs, flag off).
 */
final class ConnectorRegistry
{
    public const TYPES = [
        'storefront' => ['label' => 'Built-in online shop', 'class' => StorefrontConnector::class],
        'woocommerce' => ['label' => 'WooCommerce', 'class' => WooCommerceConnector::class],
        'shopify' => ['label' => 'Shopify', 'class' => ShopifyConnector::class],
        'rest' => ['label' => 'Generic REST + webhooks', 'class' => RestConnector::class],
        'jumia' => ['label' => 'Jumia (planned)', 'class' => null],
        'glovo' => ['label' => 'Glovo (delivery app)', 'class' => DeliveryConnector::class],
        'bolt_food' => ['label' => 'Bolt Food (delivery app)', 'class' => DeliveryConnector::class],
        'chowdeck' => ['label' => 'Chowdeck (delivery app)', 'class' => DeliveryConnector::class],
        'whatsapp' => ['label' => 'WhatsApp catalog (planned)', 'class' => null],
    ];

    /** Delivery apps: orders in only, turned into kitchen bills (Restaurant). */
    public const DELIVERY = ['bolt_food', 'glovo', 'chowdeck'];

    public static function isDelivery(string $type): bool
    {
        return in_array($type, self::DELIVERY, true);
    }

    public function for(string $type): ChannelConnector
    {
        $class = self::TYPES[$type]['class'] ?? null;
        if (! $class) {
            throw new DomainException(($this->label($type)).' is not available yet.', 'connector_unavailable');
        }

        return app($class);
    }

    public function isAvailable(string $type): bool
    {
        return (self::TYPES[$type]['class'] ?? null) !== null;
    }

    public function label(string $type): string
    {
        return self::TYPES[$type]['label'] ?? $type;
    }

    /** @return array<string, string> */
    public static function availableOptions(): array
    {
        return array_map(fn ($t) => $t['label'], array_filter(self::TYPES, fn ($t) => $t['class'] !== null));
    }
}
