<?php

namespace App\Domain\Channels;

use App\Domain\Channels\Connectors\ConnectorRegistry;
use App\Domain\Inventory\StockAvailability;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Sync\ReviewQueue;
use App\Models\ChannelJob;
use App\Models\ChannelProduct;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\SalesChannel;
use App\Models\SalesOrder;
use App\Support\Qty;
use App\Support\TenantContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Keeps a store in step with the ERP. Changes become coalesced jobs (one per product / order), run
 * with exponential backoff and jitter, and dead-lettered after repeated failure (and raised in the
 * Review queue). A push that would change nothing is skipped by hash.
 */
final class ChannelSync
{
    public const MAX_ATTEMPTS = 8;

    public function __construct(
        private readonly ConnectorRegistry $connectors,
        private readonly OutboxConsumers $consumers,
        private readonly PriceResolver $prices,
        private readonly StockAvailability $availability,
        private readonly ReviewQueue $reviews,
    ) {}

    /** Turn new outbox events into jobs for this channel. */
    public function dispatch(SalesChannel $channel): int
    {
        if (! $channel->isActive() || $channel->type === 'storefront' || ConnectorRegistry::isDelivery($channel->type)) {
            return 0; // delivery apps take orders only
        }

        return $this->consumers->consume('channel:'.$channel->id, function (OutboxEvent $e) use ($channel) {
            $snapshot = $e->payload['snapshot']['row'] ?? null;
            match ($e->aggregate_type) {
                'product' => $this->queue($channel, 'product', $e->aggregate_id),
                'stock_movement_batch' => collect($e->payload['movements'] ?? [])->where('warehouse_id', $channel->warehouse_id)
                    ->pluck('product_id')->unique()->each(fn ($id) => $this->queue($channel, 'product', $id)),
                'price_list', 'tax_group', 'tax_rate' => ($e->aggregate_type !== 'price_list' || $e->aggregate_id === $channel->price_list_id)
                    ? $this->queueAll($channel) : null,
                'sales_order' => $this->onOrderEvent($channel, $e, $snapshot),
                default => null,
            };
        }, filter: fn (OutboxEvent $e) => $e->tenant_id === $channel->tenant_id);
    }

    /** Push everything (first activation, or after a reconciliation found differences). */
    public function queueAll(SalesChannel $channel): int
    {
        $n = 0;
        foreach (Product::withoutGlobalScopes()->where('tenant_id', $channel->tenant_id)->pluck('id') as $id) {
            $this->queue($channel, 'product', $id);
            $n++;
        }

        return $n;
    }

    public function queue(SalesChannel $channel, string $kind, string $subjectId): void
    {
        DB::table('channel_jobs')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'tenant_id' => $channel->tenant_id, 'channel_id' => $channel->id, 'kind' => $kind,
            'subject_id' => $subjectId, 'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]); // a pending job for the same subject already covers it (partial unique index)
    }

    /** Run due jobs. @return array{done: int, failed: int} */
    public function runDue(SalesChannel $channel, int $limit = 100): array
    {
        $stats = ['done' => 0, 'failed' => 0];
        $connector = $this->connectors->for($channel->type);
        $jobs = ChannelJob::withoutGlobalScopes()->where('channel_id', $channel->id)->where('status', 'pending')
            ->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit($limit)->get();
        foreach ($jobs as $job) {
            try {
                match ($job->kind) {
                    'product' => $this->pushProduct($channel, $connector, $job->subject_id),
                    'order_status' => $connector->pushOrderStatus($channel, SalesOrder::withoutGlobalScopes()->findOrFail($job->subject_id)),
                };
                $job->forceFill(['status' => 'done', 'last_error' => null])->save();
                $stats['done']++;
            } catch (DomainException|ConnectionException $e) {
                $this->fail($channel, $job, $e);
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /** @return array{price_minor: ?int, available: int} for the product's base unit on this channel */
    public function stateFor(SalesChannel $channel, Product $product, ProductUom $unit): array
    {
        $price = $this->prices->resolve($channel->priceList, $product->id, $unit->id, 1000, now());
        $availableMilli = $this->availability->available($channel->warehouse_id, [$product->id], Qty::toMilli((string) $channel->safety_stock))[$product->id];
        // base thousandths → whole units of this unit: (milli / 1000) / (factorMicro / 1e6), rounded down
        $inUnit = intdiv(max(0, $availableMilli) * 1000, max(1, Qty::factorMicro($unit->factor)));

        return ['price_minor' => $price?->amount_minor, 'available' => $inUnit];
    }

    private function pushProduct(SalesChannel $channel, $connector, string $productId): void
    {
        $product = Product::withoutGlobalScopes()->find($productId);
        $unit = $product ? ProductUom::where('product_id', $product->id)->where('is_base', true)->first() : null;
        if (! $product || ! $unit) {
            return;
        }
        app(TenantContext::class)->set($channel->tenant_id);
        $mapping = ChannelProduct::withoutGlobalScopes()->firstOrNew(['channel_id' => $channel->id, 'product_uom_id' => $unit->id],
            ['id' => (string) Str::uuid7(), 'tenant_id' => $channel->tenant_id, 'product_id' => $product->id]);
        $state = $this->stateFor($channel, $product, $unit);
        $hash = hash('sha256', json_encode([$product->name, $product->sku, $product->is_active, $product->track_stock, $state]));
        if ($mapping->exists && $mapping->external_id && $mapping->last_pushed_hash === $hash) {
            return; // the store already shows exactly this
        }
        if (! $mapping->exists && $state['price_minor'] === null) {
            return; // not for sale on this channel's list and never published: nothing to create
        }
        $ids = $connector->pushProduct($channel, $product, $mapping, $state);
        $mapping->forceFill($ids + ['last_pushed_hash' => $hash, 'last_pushed_at' => now(), 'last_error' => null])->save();
    }

    private function onOrderEvent(SalesChannel $channel, OutboxEvent $e, ?array $row): void
    {
        if (($row['channel_id'] ?? null) !== $channel->id) {
            return;
        }
        if ($e->event_type === 'sales_order.received') {
            // its reservation changed what is available on this store
            foreach (DB::table('sales_order_lines')->where('sales_order_id', $e->aggregate_id)->pluck('product_id')->unique() as $pid) {
                $this->queue($channel, 'product', $pid);
            }

            return;
        }
        $this->queue($channel, 'order_status', $e->aggregate_id);
    }

    private function fail(SalesChannel $channel, ChannelJob $job, \Throwable $e): void
    {
        $attempts = $job->attempts + 1;
        $retryable = $e instanceof ConnectionException || ($e instanceof DomainException && $e->errorCode === 'retryable');
        $dead = $attempts >= ($retryable ? self::MAX_ATTEMPTS : 3);
        // 30 s, 1, 2, 4 … min, capped at 6 h, with ±20 % jitter so stores are not hammered in lockstep.
        $delay = min(30 * 2 ** ($attempts - 1), 6 * 3600);
        $delay = (int) round($delay * (0.8 + mt_rand() / mt_getrandmax() * 0.4));
        $job->forceFill(['attempts' => $attempts, 'last_error' => mb_substr($e->getMessage(), 0, 1000),
            'status' => $dead ? 'dead' : 'pending', 'next_attempt_at' => now()->addSeconds($delay)])->save();
        if ($dead) {
            DB::transaction(fn () => $this->reviews->add(Actor::system($channel->tenant_id, "Online channel {$channel->name}", 'channel'),
                'channel_failed', 'channel_job', $job->id, "{$channel->name}: gave up on {$job->kind} after {$attempts} attempts — {$e->getMessage()}",
                ['subject_id' => $job->subject_id], $channel->branch_id));
        }
    }
}
