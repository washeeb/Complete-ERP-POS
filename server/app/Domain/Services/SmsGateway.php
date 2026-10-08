<?php

namespace App\Domain\Services;

use App\Support\Settings;

/** The SMS gateway address for appointment messages: the Services one, or else the one set up for restaurant bookings. */
final class SmsGateway
{
    public static function url(Settings $settings, string $tenantId): string
    {
        return trim((string) $settings->get('services.sms_webhook_url', $tenantId)) ?: trim((string) $settings->get('restaurant.sms_webhook_url', $tenantId));
    }
}
