<?php

namespace App\Domain\Inventory;

use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Outbox;
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
 * The only writer of stock. Appends movements and moves the on-hand projection in the caller's
 * transaction. Level rows are locked in product-id order, so two tills selling the same items
 * can never deadlock or lose an update.
 */
final class StockLedger
{
    public function __construct(private readonly Settings $settings, private readonly Outbox $outbox) {}

    /**
     * @param  list<array{product: Product, unit: ProductUom, qty_milli: int, unit_cost_minor?: ?int}>  $entries  qty signed, in the unit
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
            $base = Qty::toBaseMilli($e['qty_milli'], Qty::factorMicro($e['unit']->factor));
            $deltas[$e['product']->id] = ($deltas[$e['product']->id] ?? 0) + $base;
        }
        $productIds = array_keys($deltas);
        sort($productIds);

        $now = now();
        $rows = array_map(fn ($id) => ['tenant_id' => $warehouse->tenant_id, 'warehouse_id' => $warehouse->id,
            'product_id' => $id, 'on_hand' => 0, 'updated_at' => $now], $productIds);
        DB::table('stock_levels')->insertOrIgnore($rows);
        $levels = DB::table('stock_levels')->where('warehouse_id', $warehouse->id)->whereIn('product_id', $productIds)
            ->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');

        $blockNegativeSales = (bool) $this->settings->get('stock.block_negative_sales', $warehouse->tenant_id);
        foreach ($productIds as $id) {
            $after = Qty::toMilli($levels[$id]->on_hand) + $deltas[$id];
            $outgoing = $deltas[$id] < 0;
            $mustNotGoNegative = ! $alreadyHappened
                && ($type === 'sale' ? $blockNegativeSales : in_array($type, ['adjustment', 'opening'], true));
            if ($outgoing && $after < 0 && $mustNotGoNegative) {
                $name = collect($entries)->first(fn ($e) => $e['product']->id === $id)['product']->name;
                throw new DomainException("Not enough {$name} in {$warehouse->name}: on hand ".Qty::format(Qty::toMilli($levels[$id]->on_hand)).'.', 'insufficient_stock');
            }
            DB::table('stock_levels')->where('warehouse_id', $warehouse->id)->where('product_id', $id)
                ->update(['on_hand' => Qty::format($after), 'updated_at' => $now]);
        }

        $movements = [];
        foreach ($entries as $e) {
            $m = new StockMovement;
            $m->forceFill([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $warehouse->tenant_id,
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'product_id' => $e['product']->id,
                'qty_base' => Qty::format(Qty::toBaseMilli($e['qty_milli'], Qty::factorMicro($e['unit']->factor))),
                'uom_id' => $e['unit']->uom_id,
                'qty_entered' => Qty::format($e['qty_milli']),
                'factor' => $e['unit']->factor,
                'type' => $type,
                'reason' => $reason,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'unit_cost_minor' => $e['unit_cost_minor'] ?? null,
                'actor_id' => $actor->id,
                'actor_name' => $actor->name,
                'device_id' => $actor->deviceId,
                'origin_node' => (string) config('erp.node.id'),
                'occurred_at' => $occurredAt ?? $now,
                'received_at' => $now,
            ])->save();
            $movements[] = $m;
        }
        // Other nodes receive the movements themselves (not a recalculated quantity) and add them to their ledger.
        $this->outbox->publishRaw($actor, 'stock_movement_batch', $movements[0]->id, 1, "stock.{$type}",
            ['movements' => array_map(fn (StockMovement $m) => $m->getAttributes(), $movements)]);

        return $movements;
    }

    /** Products whose projection disagrees with the ledger (should always be empty). */
    public function check(?string $tenantId = null): array
    {
        return DB::select(<<<'SQL'
            SELECT l.warehouse_id, l.product_id, l.on_hand, COALESCE(SUM(m.qty_base), 0) AS ledger
            FROM stock_levels l
            LEFT JOIN stock_movements m ON m.warehouse_id = l.warehouse_id AND m.product_id = l.product_id
            WHERE (?::uuid IS NULL OR l.tenant_id = ?::uuid)
            GROUP BY l.warehouse_id, l.product_id, l.on_hand
            HAVING l.on_hand <> COALESCE(SUM(m.qty_base), 0)
        SQL, [$tenantId, $tenantId]);
    }
}
