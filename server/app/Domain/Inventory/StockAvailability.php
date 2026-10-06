<?php

namespace App\Domain\Inventory;

use App\Domain\Support\Actor;
use App\Models\StockReservation;
use App\Support\Qty;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * What can still be promised online: on hand − active reservations − the channel's safety stock.
 * Safety stock exists because branch tills may be selling offline right now (spec §4.2): stores are
 * never told about the last few units. Tills sell physical stock and ignore reservations.
 */
final class StockAvailability
{
    /** @param list<string> $productIds @return array<string, int> product id => available base thousandths (may be negative) */
    public function available(string $warehouseId, array $productIds, int $safetyMilli = 0): array
    {
        if ($productIds === []) {
            return [];
        }
        $onHand = DB::table('stock_levels')->where('warehouse_id', $warehouseId)->whereIn('product_id', $productIds)->pluck('on_hand', 'product_id');
        $reserved = DB::table('stock_reservations')->where('warehouse_id', $warehouseId)->whereIn('product_id', $productIds)
            ->where('status', 'active')->groupBy('product_id')->selectRaw('product_id, sum(qty_base) as q')->pluck('q', 'product_id');
        $out = [];
        foreach ($productIds as $id) {
            $out[$id] = Qty::toMilli((string) ($onHand[$id] ?? '0')) - Qty::toMilli((string) ($reserved[$id] ?? '0')) - $safetyMilli;
        }

        return $out;
    }

    /**
     * Reserve in the caller's transaction. Rows are locked in product order (same as StockLedger), so
     * two orders for the last unit are decided one after the other. Always records the full quantity
     * (the customer has ordered it); returns the shortfall so the order can be flagged.
     *
     * @param  array<string, int>  $wanted  product id => base thousandths
     * @return array<string, int> product id => shortfall (only products that were short)
     */
    public function reserve(Actor $actor, string $warehouseId, array $wanted, int $safetyMilli, string $sourceType, string $sourceId): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Reservations must be made inside the domain transaction.');
        }
        $ids = array_keys($wanted);
        sort($ids);
        DB::table('stock_levels')->insertOrIgnore(array_map(fn ($id) => ['tenant_id' => $actor->tenantId, 'warehouse_id' => $warehouseId,
            'product_id' => $id, 'on_hand' => 0, 'updated_at' => now()], $ids));
        DB::table('stock_levels')->where('warehouse_id', $warehouseId)->whereIn('product_id', $ids)->orderBy('product_id')->lockForUpdate()->get();

        $available = $this->available($warehouseId, $ids, $safetyMilli);
        $short = [];
        foreach ($ids as $id) {
            if ($wanted[$id] > $available[$id]) {
                $short[$id] = $wanted[$id] - max(0, $available[$id]);
            }
            (new StockReservation)->forceFill([
                'id' => (string) Str::uuid7(), 'tenant_id' => $actor->tenantId, 'warehouse_id' => $warehouseId, 'product_id' => $id,
                'qty_base' => Qty::format($wanted[$id]), 'source_type' => $sourceType, 'source_id' => $sourceId, 'status' => 'active',
            ])->save();
        }

        return $short;
    }

    /** consumed (goods shipped) or released (order cancelled). */
    public function settle(string $sourceType, string $sourceId, string $status): int
    {
        return DB::table('stock_reservations')->where('source_type', $sourceType)->where('source_id', $sourceId)
            ->where('status', 'active')->update(['status' => $status, 'updated_at' => now()]);
    }
}
