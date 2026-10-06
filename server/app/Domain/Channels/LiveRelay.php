<?php

namespace App\Domain\Channels;

use App\Events\LiveUpdate;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\SalesChannel;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * Committed changes → live messages:
 *  - tills (private tenant.{id}.catalog / .stock.{warehouse}): "these products changed" → they re-fetch their catalog;
 *  - back office (private tenant.{id}.orders): "new online order";
 *  - storefronts (public storefront.{channel}): new price / availability per product, public figures only.
 * A failed broadcast never blocks anything: clients also poll (fallback).
 */
final class LiveRelay
{
    public function __construct(private readonly OutboxConsumers $consumers, private readonly ChannelSync $sync) {}

    public function run(): int
    {
        return $this->consumers->consume('live', function (OutboxEvent $e) {
            app(TenantContext::class)->set($e->tenant_id);
            $t = $e->tenant_id;
            $productIds = match (true) {
                $e->aggregate_type === 'product' => [$e->aggregate_id],
                $e->aggregate_type === 'stock_movement_batch' => collect($e->payload['movements'] ?? [])->pluck('product_id')->unique()->values()->all(),
                $e->aggregate_type === 'sales_order' => collect($e->payload['snapshot']['children']['sales_order_lines'] ?? [])->pluck('product_id')->unique()->values()->all(),
                default => [],
            };

            if (in_array($e->sync_group, ['catalog', 'pricing'], true)) {
                $this->send("tenant.{$t}.catalog", true, 'catalog.changed', ['product_ids' => $productIds, 'event' => $e->event_type]);
            }
            if ($e->aggregate_type === 'stock_movement_batch') {
                foreach (collect($e->payload['movements'] ?? [])->groupBy('warehouse_id') as $wh => $rows) {
                    $this->send("tenant.{$t}.stock.{$wh}", true, 'stock.changed', ['product_ids' => $rows->pluck('product_id')->unique()->values()->all()]);
                }
            }
            if ($e->aggregate_type === 'sales_order' && $e->event_type === 'sales_order.received') {
                $row = $e->payload['snapshot']['row'] ?? [];
                $this->send("tenant.{$t}.orders", true, 'order.received', ['id' => $e->aggregate_id, 'number' => $row['number'] ?? '', 'branch_id' => $row['branch_id'] ?? null]);
            }

            // Storefronts: price list / tax changes can touch every product; otherwise just the affected ones.
            $storefronts = SalesChannel::withoutGlobalScopes()->where('tenant_id', $t)->where('type', 'storefront')->where('status', 'active')->get();
            foreach ($storefronts as $shop) {
                $ids = in_array($e->aggregate_type, ['price_list', 'tax_group', 'tax_rate'], true)
                    ? ['*'] : $productIds;
                if ($ids === ['*']) {
                    $this->send("storefront.{$shop->id}", false, 'catalog.reload', []);

                    continue;
                }
                foreach (Product::withoutGlobalScopes()->whereIn('id', $ids)->get() as $p) {
                    $unit = ProductUom::where('product_id', $p->id)->where('is_base', true)->first();
                    if ($unit) {
                        $s = $this->sync->stateFor($shop, $p, $unit);
                        $this->send("storefront.{$shop->id}", false, 'product.updated', ['id' => $p->id, 'name' => $p->name,
                            'price_minor' => $s['price_minor'], 'available' => $p->track_stock ? $s['available'] : null, 'for_sale' => (bool) $p->is_active && $s['price_minor'] !== null]);
                    }
                }
            }
        });
    }

    private function send(string $channel, bool $private, string $name, array $data): void
    {
        try {
            broadcast(new LiveUpdate($channel, $private, $name, $data));
        } catch (\Throwable $e) {
            Log::warning('Live update not delivered (clients will poll)', ['channel' => $channel, 'error' => $e->getMessage()]);
        }
    }
}
