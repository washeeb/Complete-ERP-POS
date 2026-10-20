<?php

namespace App\Domain\Catalog;

use App\Domain\Inventory\StockLedger;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Qty;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Changing the unit a product is counted in (owner request 2026-10-20), e.g. Pack → Tablet or Strip of 10 → Tablet.
 *
 * Convert ($per = how many new units in one old unit): the stock ledger is never rewritten. In each store the stock
 * leaves in the old unit and comes back in the new one (a pair of "unit_conversion" movements, per batch and bin), so
 * on-hand equals the sum of movements and the stock value is unchanged; the cost per new unit is the old cost ÷ $per.
 * The new unit becomes the base; the old one stays as a selling unit (× $per) with its prices and barcodes; every
 * other unit's factor and the reorder levels are multiplied by $per; the new unit gets prices = old price ÷ $per where
 * it had none. Past documents keep what they showed. Refused while the product is on an open document.
 *
 * Relabel (the label was wrong, same numbers): {@see CatalogService::correctBaseUnit()}, after removing an unused
 * duplicate of the new unit on the product.
 */
final class UnitChangeService
{
    /** Open documents that would end up half in the old unit: lines table, document column, document table, open statuses, name. */
    private const OPEN = [
        ['purchase_order_lines', 'purchase_order_id', 'purchase_orders', ['draft', 'pending_approval', 'approved', 'partially_received'], 'purchase order'],
        ['stock_transfer_lines', 'stock_transfer_id', 'stock_transfers', ['draft', 'in_transit'], 'transfer'],
        ['stock_count_lines', 'stock_count_id', 'stock_counts', ['counting', 'submitted'], 'stock count'],
        ['stock_adjustment_lines', 'stock_adjustment_id', 'stock_adjustments', ['draft', 'submitted'], 'stock adjustment'],
        ['stock_request_lines', 'stock_request_id', 'stock_requests', ['requested', 'approved', 'supplied'], 'stock request'],
        ['customer_order_lines', 'customer_order_id', 'customer_orders', ['quotation', 'on_hold', 'confirmed', 'partially_delivered'], 'customer order'],
        ['supplier_return_lines', 'supplier_return_id', 'supplier_returns', ['submitted'], 'return to supplier'],
        ['purchase_request_lines', 'purchase_request_id', 'purchase_requests', ['requested'], 'purchase request'],
    ];

    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly StockLedger $ledger,
        private readonly CatalogService $catalog,
    ) {}

    /** "purchase order PO-000012, transfer TRF-000003": open documents holding the product (empty when none). */
    public static function openDocuments(Product $product): array
    {
        $out = [];
        foreach (self::OPEN as [$lines, $fk, $docs, $statuses, $name]) {
            if (! Schema::hasTable($lines)) {
                continue;
            }
            foreach (DB::table($lines)->join($docs, "{$docs}.id", '=', "{$lines}.{$fk}")->where("{$lines}.product_id", $product->id)
                ->whereIn("{$docs}.status", $statuses)->distinct()->pluck("{$docs}.number") as $number) {
                $out[] = "{$name} {$number}";
            }
        }

        return $out;
    }

    public function convert(Actor $actor, Product $product, string $newUomId, string $per, string $reason): Product
    {
        $this->require($actor);
        $perMilli = Qty::toMilli($per);
        if ($perMilli <= 0 || $perMilli > 100_000_000) {
            throw new DomainException('How many of the new unit are in one '.$product->baseUom?->code.'? e.g. 10 tablets in a strip.');
        }
        if (trim($reason) === '') {
            throw new DomainException('Give the reason for the change.');
        }
        $new = Uom::find($newUomId) ?? throw new DomainException('Choose the new unit.');
        if ($new->id === $product->base_uom_id) {
            throw new DomainException("{$product->name} is already counted in {$new->code}.");
        }
        if ($product->track_serials) {
            throw new DomainException("{$product->name} has serial numbers: each unit is one item and cannot be split.");
        }
        if ($open = self::openDocuments($product)) {
            throw new DomainException("{$product->name} is on ".implode(', ', array_slice($open, 0, 5)).(count($open) > 5 ? '…' : '')
                .'. Finish or cancel those first, so nothing is left half in the old unit.', 'open_documents');
        }
        $old = ProductUom::with('uom')->where('product_id', $product->id)->where('is_base', true)->firstOrFail();
        $factor = Qty::format($perMilli); // new units in one old unit, e.g. "10.000"
        $f = (float) $factor;

        return $this->recorder->transaction(function () use ($actor, $product, $new, $old, $factor, $f, $reason) {
            $product = Product::lockForUpdate()->findOrFail($product->id);
            $note = "Unit changed: 1 {$old->uom->code} = ".rtrim(rtrim($factor, '0'), '.')." {$new->code}. {$reason}";

            // 1. Stock leaves in the old unit (per store, batch and bin), at its average cost.
            $plan = $this->stockPlan($product);
            foreach ($plan as $w => $entries) {
                $this->ledger->post($actor, Warehouse::findOrFail($w), 'unit_conversion', array_map(fn ($e) => [
                    'product' => $product, 'unit' => $old, 'qty_milli' => -$e['milli'], 'batch_id' => $e['batch_id'], 'bin_id' => $e['bin_id'] ?? false,
                    'unit_cost_minor' => $e['cost'],
                ], $entries), $note, 'product', $product->id, alreadyHappened: true);
            }

            // 2. The units: the new one is the base; the old one sells as × factor; others are multiplied.
            $target = ProductUom::where('product_id', $product->id)->where('uom_id', $new->id)->first();
            foreach (ProductUom::where('product_id', $product->id)->get() as $u) {
                if ($u->id === $target?->id) {
                    continue;
                }
                $u->forceFill(['is_base' => false, 'factor' => number_format((float) $u->factor * $f, 6, '.', ''), 'version' => $u->version + 1])->save();
            }
            $target ??= new ProductUom(['product_id' => $product->id, 'uom_id' => $new->id]);
            $target->forceFill(['tenant_id' => $product->tenant_id, 'factor' => '1', 'is_base' => true, 'is_sellable' => true, 'is_purchasable' => true,
                'version' => ($target->version ?? 0) + 1])->save();
            $product->base_uom_id = $new->id;
            $product->updated_by = $actor->id;
            $this->recorder->save($actor, $product, 'product.unit_converted', $note);

            // 3. Prices for the new unit where it had none: the old unit's price ÷ factor (each list, from 1).
            if (! Price::where('product_uom_id', $target->id)->exists()) {
                foreach (Price::where('product_uom_id', $old->id)->where('min_qty', 1)->get() as $p) {
                    (new Price)->forceFill(['tenant_id' => $p->tenant_id, 'price_list_id' => $p->price_list_id, 'product_id' => $product->id, 'product_uom_id' => $target->id,
                        'min_qty' => '1', 'amount_minor' => (int) round($p->amount_minor / $f), 'starts_at' => $p->starts_at, 'ends_at' => $p->ends_at,
                        'updated_by' => $actor->id])->save();
                }
            }

            // 4. Reorder levels are in base units.
            foreach (DB::table('reorder_rules')->where('product_id', $product->id)->get() as $r) {
                DB::table('reorder_rules')->where('id', $r->id)->update(['min_qty' => Qty::format((int) round(Qty::toMilli((string) $r->min_qty) * $f)),
                    'max_qty' => Qty::format((int) round(Qty::toMilli((string) $r->max_qty) * $f)), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            }

            // 5. Stock comes back in the new unit, at the cost per new unit.
            $base = $target->fresh();
            foreach ($plan as $w => $entries) {
                $this->ledger->post($actor, Warehouse::findOrFail($w), 'unit_conversion', array_map(fn ($e) => [
                    'product' => $product, 'unit' => $base, 'qty_milli' => (int) round($e['milli'] * $f), 'batch_id' => $e['batch_id'], 'bin_id' => $e['bin_id'] ?? false,
                    'unit_cost_minor' => $e['cost'] === null ? null : (int) round($e['cost'] / $f),
                ], $entries), $note, 'product', $product->id, alreadyHappened: true);
            }

            $this->recorder->outbox->publish($actor, $product->fresh(), 'product.details_changed', ['product_id' => $product->id, 'units' => $note]);

            return $product->fresh(['units.uom', 'baseUom']);
        });
    }

    /**
     * The label was wrong (same numbers): removes an unused copy of the new unit on the product first (no barcodes,
     * prices or history), then renames the base unit in place.
     */
    public function relabel(Actor $actor, Product $product, string $newUomId, string $reason): Product
    {
        $this->require($actor);
        $dup = ProductUom::where('product_id', $product->id)->where('uom_id', $newUomId)->where('is_base', false)->first();
        if ($dup) {
            $used = DB::table('barcodes')->where('product_uom_id', $dup->id)->exists() || DB::table('prices')->where('product_uom_id', $dup->id)->exists()
                || collect(['sale_lines', 'purchase_order_lines', 'stock_adjustment_lines', 'stock_transfer_lines', 'customer_order_lines'])
                    ->contains(fn ($t) => Schema::hasTable($t) && DB::table($t)->where('product_uom_id', $dup->id)->exists());
            if ($used) {
                throw new DomainException("{$product->name} already uses ".Uom::find($newUomId)?->code.' as another unit (with barcodes, prices or history). '
                    .'If one is really '.rtrim(rtrim((string) $dup->factor, '0'), '.').' of the other, use Convert instead.');
            }
            $this->recorder->transaction(fn () => $dup->delete());
        }

        return $this->catalog->correctBaseUnit($actor, $product->fresh(), $newUomId, $reason);
    }

    /**
     * What to move per store: [warehouse id => [['milli' => qty in old base (signed), 'batch_id', 'bin_id', 'cost' => per old unit]]].
     * Batch-tracked: one entry per batch; bins: one entry per bin; otherwise one per store.
     *
     * @return array<string, list<array{milli: int, batch_id: ?string, bin_id?: ?string, cost: ?int}>>
     */
    private function stockPlan(Product $product): array
    {
        $plan = [];
        foreach (DB::table('stock_levels as l')->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')->where('l.product_id', $product->id)
            ->where('l.on_hand', '<>', 0)->get(['l.warehouse_id', 'l.on_hand', 'l.avg_cost_minor', 'w.use_bins']) as $l) {
            $cost = $l->avg_cost_minor === null ? null : (int) round((float) $l->avg_cost_minor);
            if ($product->track_batches) {
                if ($l->use_bins) {
                    throw new DomainException("{$product->name}: batches in a store with bins are converted by the warehouse manager (ask SyncLytics).");
                }
                $rows = DB::table('stock_batch_levels')->where('warehouse_id', $l->warehouse_id)->where('product_id', $product->id)->where('on_hand', '<>', 0)->get();
                $sum = 0;
                foreach ($rows as $b) {
                    $m = Qty::toMilli((string) $b->on_hand);
                    $plan[$l->warehouse_id][] = ['milli' => $m, 'batch_id' => $b->batch_id, 'cost' => $cost];
                    $sum += $m;
                }
                if (($rest = Qty::toMilli((string) $l->on_hand) - $sum) !== 0) {
                    throw new DomainException("{$product->name}: the stock in batches does not add up to the stock on hand. Ask SyncLytics to check it before the change.");
                }

                continue;
            }
            if ($l->use_bins) {
                foreach (DB::table('stock_bin_levels')->where('warehouse_id', $l->warehouse_id)->where('product_id', $product->id)->where('on_hand', '<>', 0)->get() as $b) {
                    $plan[$l->warehouse_id][] = ['milli' => Qty::toMilli((string) $b->on_hand), 'batch_id' => null, 'bin_id' => $b->bin_id, 'cost' => $cost];
                }

                continue;
            }
            $plan[$l->warehouse_id][] = ['milli' => Qty::toMilli((string) $l->on_hand), 'batch_id' => null, 'cost' => $cost];
        }

        return $plan;
    }

    private function require(Actor $actor): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'catalog.manage')) {
            throw new AccessDenied;
        }
    }
}
