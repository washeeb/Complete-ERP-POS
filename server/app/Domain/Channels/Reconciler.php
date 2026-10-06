<?php

namespace App\Domain\Channels;

use App\Domain\Channels\Connectors\ConnectorRegistry;
use App\Models\ChannelProduct;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\SalesChannel;

/**
 * Compares what the store shows with what the ERP says it should show (price on the channel list,
 * available stock after reservations and safety stock). Differences are re-queued as pushes — the
 * ERP owns catalog, price and stock — and listed in the report.
 */
final class Reconciler
{
    public function __construct(private readonly ConnectorRegistry $connectors, private readonly ChannelSync $sync) {}

    /** @return list<array{sku: string, name: string, field: string, store: mixed, erp: mixed}> */
    public function run(SalesChannel $channel, bool $repair = true): array
    {
        $connector = $this->connectors->for($channel->type);
        if (! in_array('reconcile', $connector->capabilities(), true)) {
            return [];
        }
        $remote = $connector->remoteState($channel);
        $diffs = [];
        foreach (ChannelProduct::withoutGlobalScopes()->where('channel_id', $channel->id)->whereNotNull('external_id')->get() as $m) {
            $product = Product::withoutGlobalScopes()->find($m->product_id);
            $unit = ProductUom::find($m->product_uom_id);
            if (! $product || ! $unit) {
                continue;
            }
            $key = $m->external_variant_id ?: $m->external_id;
            $expected = $this->sync->stateFor($channel, $product, $unit);
            $store = $remote[$key] ?? null;
            if ($store === null) {
                $diffs[] = ['sku' => $product->sku, 'name' => $product->name, 'field' => 'missing on store', 'store' => null, 'erp' => 'listed'];
            } else {
                if ($store['price_minor'] !== $expected['price_minor'] && $expected['price_minor'] !== null) {
                    $diffs[] = ['sku' => $product->sku, 'name' => $product->name, 'field' => 'price', 'store' => $store['price_minor'], 'erp' => $expected['price_minor']];
                }
                if ($product->track_stock && $store['stock'] !== null && (int) $store['stock'] !== $expected['available']) {
                    $diffs[] = ['sku' => $product->sku, 'name' => $product->name, 'field' => 'stock', 'store' => (int) $store['stock'], 'erp' => $expected['available']];
                }
            }
            if ($repair && $diffs && end($diffs)['sku'] === $product->sku) {
                $m->forceFill(['last_pushed_hash' => null])->save(); // force the next push through
                $this->sync->queue($channel, 'product', $product->id);
            }
        }
        $channel->forceFill(['last_reconciled_at' => now(), 'settings' => array_merge($channel->settings ?? [], ['last_reconcile_diffs' => count($diffs)])])->saveQuietly();

        return $diffs;
    }
}
