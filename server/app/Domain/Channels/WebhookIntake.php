<?php

namespace App\Domain\Channels;

use App\Domain\Channels\Connectors\ConnectorRegistry;
use App\Domain\Restaurant\DeliveryOrders;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Sync\ReviewQueue;
use App\Models\SalesChannel;
use App\Models\WebhookInboxEntry;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Webhooks: verify the signature over the raw body, store once per delivery id, answer 2xx at once;
 * turn them into orders later (process()), retrying failures and raising them for review.
 */
final class WebhookIntake
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly ConnectorRegistry $connectors,
        private readonly OnlineOrderService $orders,
        private readonly ReviewQueue $reviews,
    ) {}

    /** @return string received | duplicate */
    public function receive(SalesChannel $channel, Request $request): string
    {
        $connector = $this->connectors->for($channel->type);
        if (! $connector->verifyWebhook($channel, $request)) {
            throw new DomainException('Webhook signature does not match.', 'bad_signature');
        }
        if (strlen($request->getContent()) > 2_000_000) {
            throw new DomainException('Webhook body too large.', 'malformed');
        }
        $meta = $connector->webhookMeta($request);
        $deliveryId = $meta['delivery_id'] !== '' ? $meta['delivery_id'] : hash('sha256', $request->getContent());
        $inserted = DB::table('webhook_inbox')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'tenant_id' => $channel->tenant_id, 'channel_id' => $channel->id,
            'delivery_id' => mb_substr($deliveryId, 0, 120), 'topic' => mb_substr($meta['topic'] ?: 'unknown', 0, 80),
            'raw_body' => $request->getContent(), 'headers' => json_encode(array_map(fn ($v) => $v[0] ?? null, $request->headers->all())),
            'status' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);

        return $inserted ? 'received' : 'duplicate';
    }

    /** @return int processed */
    public function process(SalesChannel $channel, int $limit = 50): int
    {
        app(TenantContext::class)->set($channel->tenant_id);
        $connector = $this->connectors->for($channel->type);
        $n = 0;
        $entries = WebhookInboxEntry::withoutGlobalScopes()->where('channel_id', $channel->id)->whereIn('status', ['received', 'failed'])
            ->where('attempts', '<', self::MAX_ATTEMPTS)->orderBy('received_at')->limit($limit)->get();
        foreach ($entries as $entry) {
            try {
                $payload = json_decode($entry->raw_body, true, 64, JSON_THROW_ON_ERROR);
                $input = $connector->normaliseOrder($channel, $entry->topic, $payload);
                if ($input === null) {
                    $entry->forceFill(['status' => 'ignored', 'processed_at' => now()])->save();

                    continue;
                }
                if (ConnectorRegistry::isDelivery($channel->type)) {
                    app(DeliveryOrders::class)->ingest($channel, $input); // Restaurant: a delivery bill for the kitchen
                } else {
                    $this->orders->ingest($channel, $input);
                }
                $entry->forceFill(['status' => 'processed', 'processed_at' => now(), 'last_error' => null])->save();
                $n++;
            } catch (\Throwable $e) {
                $attempts = $entry->attempts + 1;
                $entry->forceFill(['status' => 'failed', 'attempts' => $attempts, 'last_error' => mb_substr($e->getMessage(), 0, 1000)])->save();
                if ($attempts >= self::MAX_ATTEMPTS || ($e instanceof DomainException && in_array($e->errorCode, ['unmapped_line', 'malformed'], true) && $attempts === 1)) {
                    DB::transaction(fn () => $this->reviews->add(Actor::system($channel->tenant_id, "Online channel {$channel->name}", 'channel'),
                        'webhook_failed', 'webhook', $entry->id, "{$channel->name}: {$entry->topic} could not be turned into an order — {$e->getMessage()}",
                        ['delivery_id' => $entry->delivery_id], $channel->branch_id));
                }
            }
        }

        return $n;
    }
}
