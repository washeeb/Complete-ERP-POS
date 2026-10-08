<?php

namespace App\Domain\Inventory;

use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Outbox;
use App\Models\Bin;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\Qty;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * The only writer of stock. Appends movements and moves the projections (on hand, bins, average cost) in the
 * caller's transaction. Level rows are locked in product-id order, so two tills selling the same items can
 * never deadlock or lose an update.
 *
 * Bin-managed warehouses: every movement names a bin. Incoming stock without one goes to the receiving bin;
 * outgoing stock without one is taken from bins in pick order (storage before receiving).
 */
final class StockLedger
{
    /** Movement types that may not take stock below zero (sales follow the shop's setting; offline sales never refuse). */
    private const STRICT = ['adjustment', 'opening', 'transfer_out', 'putaway', 'receipt_reversal', 'delivery']; // counts record what is there

    public function __construct(private readonly Settings $settings, private readonly Outbox $outbox, private readonly StockProjection $projection,
        private readonly StockBatches $batches, private readonly SerialRegistry $serials) {}

    /** Serial-number problems met by the last post of already-happened (offline) work, for the review queue. */
    public function serialAnomalies(): array
    {
        return $this->serials->anomalies();
    }

    /**
     * @param  list<array{product: Product, unit: ProductUom, qty_milli: int, unit_cost_minor?: ?int, bin_id?: string|false|null}>  $entries  qty signed, in the unit;
     *                                                                                                                                        cost per entered unit (incoming)
     * @return list<StockMovement>
     */
    public function post(
        Actor $actor,
        Warehouse $warehouse,
        string $type,
        array $entries,
        ?string $reason = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?CarbonInterface $occurredAt = null,
        bool $alreadyHappened = false, // offline sales: the goods have left; never refuse, the shortfall is reviewed
    ): array {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Stock must be posted inside the domain transaction.');
        }
        $entries = array_values(array_filter($entries, fn ($e) => $e['product']->track_stock));
        if ($entries === []) {
            return [];
        }

        // Sum per product first so a cart with the same item twice locks it once.
        $deltas = [];
        foreach ($entries as $e) {
            if ($e['unit']->product_id !== $e['product']->id) {
                throw new DomainException("{$e['product']->name}: that unit belongs to another product.");
            }
            $deltas[$e['product']->id] = ($deltas[$e['product']->id] ?? 0) + Qty::toBaseMilli($e['qty_milli'], Qty::factorMicro($e['unit']->factor));
        }
        $productIds = array_keys($deltas);
        sort($productIds);

        $now = now();
        DB::table('stock_levels')->insertOrIgnore(array_map(fn ($id) => ['tenant_id' => $warehouse->tenant_id, 'warehouse_id' => $warehouse->id,
            'product_id' => $id, 'on_hand' => 0, 'updated_at' => $now], $productIds));
        $levels = DB::table('stock_levels')->where('warehouse_id', $warehouse->id)->whereIn('product_id', $productIds)
            ->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');

        $strict = ! $alreadyHappened && ($type === 'sale'
            ? (bool) $this->settings->get('stock.block_negative_sales', $warehouse->tenant_id)
            : in_array($type, self::STRICT, true));
        foreach ($productIds as $id) {
            $after = Qty::toMilli($levels[$id]->on_hand) + $deltas[$id];
            if ($deltas[$id] < 0 && $after < 0 && $strict) {
                throw new DomainException("Not enough {$this->nameOf($entries, $id)} in {$warehouse->name}: on hand ".Qty::format(Qty::toMilli($levels[$id]->on_hand)).'.', 'insufficient_stock');
            }
        }

        $entries = $this->withBatches($actor, $warehouse, $type, $entries, $alreadyHappened);
        $this->serials->begin();

        $rows = [];
        $firstRow = [];
        foreach ($entries as $idx => $e) {
            $firstRow[$idx] = count($rows);
            $avg = $levels[$e['product']->id]->avg_cost_minor;
            // Outgoing stock is valued at the current average (cost of goods); incoming carries its own cost, or the average
            // when it has none (returns, count gains), so the stock value in the ledger follows the goods. A goods-received
            // reversal takes the goods out at the cost they came in at, so goods-received-not-billed clears exactly.
            $atAverage = StockProjection::costPerUnit($avg === null ? null : (string) $avg, (string) $e['unit']->factor);
            $cost = match (true) {
                $type === 'receipt_reversal' => $e['unit_cost_minor'] ?? $atAverage,
                $e['qty_milli'] > 0 => $e['unit_cost_minor'] ?? ($type === 'putaway' ? null : $atAverage),
                default => $atAverage,
            };
            foreach ($this->binSplit($warehouse, $e, $strict) as [$binId, $qtyMilli]) {
                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $warehouse->tenant_id,
                    'branch_id' => $warehouse->branch_id,
                    'warehouse_id' => $warehouse->id,
                    'bin_id' => $binId,
                    'batch_id' => $e['batch_id'] ?? null,
                    'product_id' => $e['product']->id,
                    'qty_base' => Qty::format(Qty::toBaseMilli($qtyMilli, Qty::factorMicro($e['unit']->factor))),
                    'uom_id' => $e['unit']->uom_id,
                    'qty_entered' => Qty::format($qtyMilli),
                    'factor' => (string) $e['unit']->factor,
                    'type' => $type,
                    'reason' => $reason,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'unit_cost_minor' => $cost,
                    'actor_id' => $actor->id,
                    'actor_name' => $actor->name,
                    'device_id' => $actor->deviceId,
                    'origin_node' => (string) config('erp.node.id'),
                    'occurred_at' => $occurredAt ?? $now,
                    'received_at' => $now,
                ];
            }
        }
        $movements = array_map(function ($r) {
            $m = new StockMovement;
            $m->forceFill($r)->save();

            return $m;
        }, $rows);
        $this->projection->apply(array_map(fn (StockMovement $m) => $m->getAttributes(), $movements));
        foreach ($entries as $idx => $e) { // which units moved (serial-numbered products)
            $this->serials->apply($warehouse, $type, $e, $movements[$firstRow[$idx]]->id, $alreadyHappened, $occurredAt ?? $now);
        }
        $serials = $this->serials->changes();

        // Other nodes receive the movements themselves (not a recalculated quantity) and add them to their ledger.
        $this->outbox->publishRaw($actor, 'stock_movement_batch', $movements[0]->id, 1, "stock.{$type}",
            ['movements' => array_map(fn (StockMovement $m) => $m->getAttributes(), $movements)] + ($serials['rows'] ? ['serials' => $serials] : []));

        return $movements;
    }

    /**
     * Which bin(s) an entry touches.
     *
     * @return list<array{0: ?string, 1: int}> [bin id, qty in the entry unit]
     */
    private function binSplit(Warehouse $warehouse, array $e, bool $strict): array
    {
        if (! $warehouse->use_bins || (array_key_exists('bin_id', $e) && $e['bin_id'] === false)) {
            return [[null, $e['qty_milli']]]; // bin_id false: explicitly outside bins (stock placed in bins when they are switched on)
        }
        if (! empty($e['bin_id'])) {
            $bin = Bin::where('warehouse_id', $warehouse->id)->find($e['bin_id']) ?? throw new DomainException('That bin is not in this warehouse.');
            if ($e['qty_milli'] < 0 && $strict) {
                $have = Qty::toMilli((string) (DB::table('stock_bin_levels')->where('bin_id', $bin->id)->where('product_id', $e['product']->id)->lockForUpdate()->value('on_hand') ?? '0'));
                $need = Qty::toBaseMilli(-$e['qty_milli'], Qty::factorMicro($e['unit']->factor));
                if ($need > $have) {
                    throw new DomainException('Only '.Qty::format($have)." {$e['product']->name} in bin {$bin->code}.", 'insufficient_stock');
                }
            }

            return [[$bin->id, $e['qty_milli']]];
        }
        $receiving = BinService::receivingBin($warehouse);
        if ($e['qty_milli'] > 0) {
            return [[$receiving->id, $e['qty_milli']]];
        }

        // Take from bins holding stock, in pick order; whatever cannot be covered comes from the receiving bin.
        $factor = Qty::factorMicro($e['unit']->factor);
        $needBase = Qty::toBaseMilli(-$e['qty_milli'], $factor);
        $stocked = DB::table('stock_bin_levels')->join('bins', 'bins.id', '=', 'stock_bin_levels.bin_id')
            ->where('stock_bin_levels.warehouse_id', $warehouse->id)->where('stock_bin_levels.product_id', $e['product']->id)
            ->where('stock_bin_levels.on_hand', '>', 0)
            ->orderByRaw("CASE bins.kind WHEN 'receiving' THEN 1 ELSE 0 END")->orderBy('bins.pick_order')->orderBy('bins.code')
            ->lockForUpdate()->get(['stock_bin_levels.bin_id', 'stock_bin_levels.on_hand']);
        $split = [];
        foreach ($stocked as $row) {
            if ($needBase <= 0) {
                break;
            }
            $take = min($needBase, Qty::toMilli((string) $row->on_hand));
            if ($factor === 1_000_000) {
                $split[] = [$row->bin_id, -$take];
                $needBase -= $take;
            } else {
                // Larger units are not split across bins: take whole entered units from one bin where possible.
                $units = intdiv($take * 1_000_000, $factor * 1000) * 1000;
                if ($units > 0) {
                    $split[] = [$row->bin_id, -$units];
                    $needBase -= Qty::toBaseMilli($units, $factor);
                }
            }
        }
        if ($needBase > 0) {
            $rest = $factor === 1_000_000 ? $needBase : Qty::divRound($needBase * 1_000_000, $factor);
            $split[] = [$receiving->id, -$rest];
        }

        return $split;
    }

    private function nameOf(array $entries, string $productId): string
    {
        return collect($entries)->first(fn ($e) => $e['product']->id === $productId)['product']->name;
    }

    /**
     * Batch-tracked products: every movement names its batch. Stock going out without one is taken earliest expiry
     * first (sales and deliveries never from expired batches, unless an offline sale already happened); stock coming in
     * without one joins the newest batch — except receipts and opening stock, which must say which batch it is.
     * Moves between bins stay outside batches (they do not change what the warehouse holds).
     */
    private function withBatches(Actor $actor, Warehouse $warehouse, string $type, array $entries, bool $alreadyHappened): array
    {
        if (in_array($type, ['putaway', 'batch_assign'], true)) {
            return $entries;
        }
        $out = [];
        foreach ($entries as $e) {
            if (! $e['product']->track_batches || ! empty($e['batch_id'])) {
                $out[] = $e;

                continue;
            }
            if ($e['qty_milli'] > 0) {
                if (in_array($type, ['receipt', 'opening'], true)) {
                    throw new DomainException("{$e['product']->name}: enter the batch number and expiry date.", 'batch_required');
                }
                $out[] = ['batch_id' => $this->batches->latest($actor, $warehouse, $e['product'])] + $e;

                continue;
            }
            $factor = Qty::factorMicro($e['unit']->factor);
            $split = $this->batches->fefo($actor, $warehouse, $e['product'], Qty::toBaseMilli(-$e['qty_milli'], $factor), in_array($type, ['sale', 'delivery'], true), $alreadyHappened);
            if (count($split) === 1) {
                $out[] = ['batch_id' => $split[0][0]] + $e;

                continue;
            }
            $base = ProductUom::where('product_id', $e['product']->id)->where('is_base', true)->firstOrFail();
            foreach ($split as [$batchId, $qtyBase]) {
                $out[] = ['batch_id' => $batchId, 'unit' => $base, 'qty_milli' => -$qtyBase] + $e;
            }
        }

        return $out;
    }

    /** Switching batch tracking on for a product with stock: what is on hand becomes batch "OPENING" (no value moves). */
    public function assignOpeningBatches(Actor $actor, Product $product): void
    {
        $base = ProductUom::where('product_id', $product->id)->where('is_base', true)->firstOrFail();
        foreach (DB::table('stock_levels')->where('product_id', $product->id)->get() as $level) {
            $inBatches = Qty::toMilli((string) DB::table('stock_batch_levels')->where('warehouse_id', $level->warehouse_id)->where('product_id', $product->id)->sum('on_hand'));
            $loose = Qty::toMilli((string) $level->on_hand) - $inBatches;
            if ($loose === 0) {
                continue;
            }
            $batch = $this->batches->ensure($actor, $product, 'OPENING', null);
            $this->post($actor, Warehouse::findOrFail($level->warehouse_id), 'batch_assign', [
                ['product' => $product, 'unit' => $base, 'qty_milli' => -$loose, 'bin_id' => false],
                ['product' => $product, 'unit' => $base, 'qty_milli' => $loose, 'bin_id' => false, 'batch_id' => $batch],
            ], 'Batch tracking started', 'product', $product->id, alreadyHappened: true);
        }
    }

    /** Products whose projections disagree with the ledger (should always be empty). */
    public function check(?string $tenantId = null): array
    {
        $warehouse = DB::select(<<<'SQL'
            SELECT l.warehouse_id, NULL AS bin_id, l.product_id, l.on_hand, COALESCE(SUM(m.qty_base), 0) AS ledger
            FROM stock_levels l
            LEFT JOIN stock_movements m ON m.warehouse_id = l.warehouse_id AND m.product_id = l.product_id
            WHERE (?::uuid IS NULL OR l.tenant_id = ?::uuid)
            GROUP BY l.warehouse_id, l.product_id, l.on_hand
            HAVING l.on_hand <> COALESCE(SUM(m.qty_base), 0)
        SQL, [$tenantId, $tenantId]);
        $bins = DB::select(<<<'SQL'
            SELECT l.warehouse_id, l.bin_id, l.product_id, l.on_hand, COALESCE(SUM(m.qty_base), 0) AS ledger
            FROM stock_bin_levels l
            LEFT JOIN stock_movements m ON m.bin_id = l.bin_id AND m.product_id = l.product_id
            WHERE (?::uuid IS NULL OR l.tenant_id = ?::uuid)
            GROUP BY l.warehouse_id, l.bin_id, l.product_id, l.on_hand
            HAVING l.on_hand <> COALESCE(SUM(m.qty_base), 0)
        SQL, [$tenantId, $tenantId]);

        // In a bin-managed warehouse every unit is in some bin: the warehouse total must equal the sum of its bins.
        $unbinned = DB::select(<<<'SQL'
            SELECT l.warehouse_id, NULL AS bin_id, l.product_id, l.on_hand, COALESCE(SUM(b.on_hand), 0) AS ledger
            FROM stock_levels l
            JOIN warehouses w ON w.id = l.warehouse_id AND w.use_bins
            LEFT JOIN stock_bin_levels b ON b.warehouse_id = l.warehouse_id AND b.product_id = l.product_id
            WHERE (?::uuid IS NULL OR l.tenant_id = ?::uuid)
            GROUP BY l.warehouse_id, l.product_id, l.on_hand
            HAVING l.on_hand <> COALESCE(SUM(b.on_hand), 0)
        SQL, [$tenantId, $tenantId]);

        return array_merge($warehouse, $bins, $unbinned, StockBatches::check($tenantId));
    }
}
