<?php

namespace App\Domain\Api;

use App\Domain\Channels\OutboxConsumers;
use App\Models\OutboxEvent;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Public API webhooks: committed events become signed POSTs to the addresses that asked for them. Each event has one id
 * kept across retries (receivers de-duplicate on it). Retries back off from 1 minute to 6 hours; after 10 failures a
 * delivery is dead, and an address that fails 50 times in a row is switched off.
 */
final class WebhookDispatcher
{
    private const BACKOFF = [1, 5, 15, 30, 60, 120, 240, 360, 360];

    public const MAX_ATTEMPTS = 10;

    public function __construct(private readonly OutboxConsumers $consumers) {}

    public function run(): int
    {
        return $this->enqueue() + $this->deliverDue();
    }

    /** Public event name for an internal one (null = not published). */
    public static function eventOf(OutboxEvent $e): ?string
    {
        return match (true) {
            $e->aggregate_type === 'product' => 'product.updated',
            $e->aggregate_type === 'customer' && in_array($e->event_type, ['customer.created', 'customer.updated', 'customer.credit_changed'], true) => 'customer.updated',
            $e->aggregate_type === 'sale' && in_array($e->event_type, ['sale.posted', 'sale.posted_offline', 'sale.returned'], true) => 'sale.posted',
            $e->event_type === 'invoice.posted' => 'invoice.posted',
            in_array($e->event_type, ['customer_order.confirmed', 'customer_order.delivered'], true) => $e->event_type,
            default => null,
        };
    }

    private function enqueue(): int
    {
        return $this->consumers->consume('webhooks', function (OutboxEvent $e) {
            $event = self::eventOf($e);
            $row = $e->payload['snapshot']['row'] ?? [];
            $data = array_filter(['id' => $e->aggregate_id, 'number' => $row['number'] ?? $row['receipt_no'] ?? null, 'sku' => $row['sku'] ?? null,
                'name' => $row['name'] ?? null, 'status' => $row['status'] ?? null, 'total_minor' => isset($row['total_minor']) ? (int) $row['total_minor'] : null,
                'customer_id' => $row['customer_id'] ?? null, 'branch_id' => $row['branch_id'] ?? null], fn ($v) => $v !== null);
            if (Tenant::isPractice($e->tenant_id)) {
                return; // a practice company's events never reach other systems
            }
            foreach (WebhookEndpoint::withoutGlobalScopes()->where('tenant_id', $e->tenant_id)->where('is_active', true)->get() as $endpoint) {
                if (in_array($event, $endpoint->events ?? [], true)) {
                    WebhookDelivery::create(['webhook_endpoint_id' => $endpoint->id, 'event' => $event, 'event_id' => $e->id, 'status' => 'pending', 'next_attempt_at' => now(),
                        'payload' => ['id' => $e->id, 'type' => $event, 'created_at' => $e->occurred_at?->toIso8601String(), 'data' => $data]]);
                }
            }
        }, 500, fn (OutboxEvent $e) => self::eventOf($e) !== null);
    }

    public function deliverDue(int $limit = 50): int
    {
        $n = 0;
        foreach (WebhookDelivery::where('status', 'pending')->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit($limit)->get() as $d) {
            $endpoint = WebhookEndpoint::withoutGlobalScopes()->find($d->webhook_endpoint_id);
            if (! $endpoint?->is_active) {
                $d->forceFill(['status' => 'dead', 'error' => 'Webhook switched off'])->save();

                continue;
            }
            $body = json_encode($d->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $t = time();
            $d->attempts++;
            try {
                $res = Http::timeout(10)->withHeaders(['X-Synclytics-Event' => $d->event, 'X-Synclytics-Delivery' => $d->event_id,
                    'X-Synclytics-Signature' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", (string) $endpoint->secret)])
                    ->withBody($body, 'application/json')->post($endpoint->url);
                $ok = $res->successful();
                $code = $res->status();
                $error = $ok ? null : "HTTP {$code}";
            } catch (Throwable $e) {
                [$ok, $code, $error] = [false, null, Str::limit($e->getMessage(), 200)];
            }
            $dead = ! $ok && $d->attempts >= self::MAX_ATTEMPTS;
            $d->forceFill(['status' => $ok ? 'sent' : ($dead ? 'dead' : 'pending'), 'response_code' => $code, 'error' => $error,
                'next_attempt_at' => $ok || $dead ? null : now()->addMinutes(self::BACKOFF[min($d->attempts - 1, count(self::BACKOFF) - 1)])])->save();
            $endpoint->forceFill(['failures' => $ok ? 0 : $endpoint->failures + 1, 'is_active' => $ok || $endpoint->failures + 1 < 50])->save();
            $n++;
        }

        return $n;
    }
}
