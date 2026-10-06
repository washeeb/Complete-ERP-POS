<?php

namespace App\Domain\Inventory;

use App\Support\Qty;
use Illuminate\Support\Facades\DB;

/**
 * Moves the read projections for movements that were just inserted: warehouse on-hand, bin on-hand and the
 * weighted-average cost. Used by the ledger on this node and by sync for movements from other nodes, so both
 * compute the same way. Must run inside the transaction that inserted the movements.
 */
final class StockProjection
{
    /**
     * Weighted average per base unit (owner decision 2026-10-09). Stock below zero is treated as zero when
     * averaging, so a receipt after an oversell is not distorted. Incoming stock without a cost (e.g. a count gain)
     * leaves the average unchanged.
     */
    public static function newAverage(?string $avg, int $onHandBeforeMilli, int $inMilli, ?string $costPerBase): ?string
    {
        if ($costPerBase === null || $inMilli <= 0) {
            return $avg;
        }
        $before = max(0, $onHandBeforeMilli);
        if ($avg === null || $before === 0) {
            return number_format((float) $costPerBase, 4, '.', '');
        }
        $value = bcadd(bcmul((string) $before, $avg, 8), bcmul((string) $inMilli, $costPerBase, 8), 8);

        return bcdiv($value, (string) ($before + $inMilli), 4);
    }

    /** @param list<array<string, mixed>> $movements raw movement rows (qty_base as "1.000" strings) */
    public function apply(array $movements): void
    {
        $now = now();
        foreach ($movements as $m) {
            $qty = Qty::toMilli((string) $m['qty_base']);
            DB::table('stock_levels')->insertOrIgnore(['tenant_id' => $m['tenant_id'], 'warehouse_id' => $m['warehouse_id'],
                'product_id' => $m['product_id'], 'on_hand' => 0, 'updated_at' => $now]);
            $level = DB::table('stock_levels')->where('warehouse_id', $m['warehouse_id'])->where('product_id', $m['product_id'])->lockForUpdate()->first();
            $before = Qty::toMilli((string) $level->on_hand);
            $avg = $level->avg_cost_minor === null ? null : (string) $level->avg_cost_minor;
            if ($qty > 0 && $m['unit_cost_minor'] !== null && ! in_array($m['type'], ['putaway'], true)) {
                $avg = self::newAverage($avg, $before, $qty, self::costPerBase((int) $m['unit_cost_minor'], (string) $m['factor']));
            }
            DB::table('stock_levels')->where('warehouse_id', $m['warehouse_id'])->where('product_id', $m['product_id'])
                ->update(['on_hand' => Qty::format($before + $qty), 'avg_cost_minor' => $avg, 'updated_at' => $now]);

            if (! empty($m['bin_id'])) {
                DB::table('stock_bin_levels')->insertOrIgnore(['tenant_id' => $m['tenant_id'], 'warehouse_id' => $m['warehouse_id'],
                    'bin_id' => $m['bin_id'], 'product_id' => $m['product_id'], 'on_hand' => 0, 'updated_at' => $now]);
                $bin = DB::table('stock_bin_levels')->where('bin_id', $m['bin_id'])->where('product_id', $m['product_id'])->lockForUpdate()->first();
                DB::table('stock_bin_levels')->where('bin_id', $m['bin_id'])->where('product_id', $m['product_id'])
                    ->update(['on_hand' => Qty::format(Qty::toMilli((string) $bin->on_hand) + $qty), 'updated_at' => $now]);
            }
        }
    }

    /** Cost per base unit from a cost per entered unit (e.g. GHS 240 per carton of 24 → 10.0000 per piece). */
    public static function costPerBase(int $unitCostMinor, string $factor): string
    {
        return bcdiv((string) $unitCostMinor, $factor, 4);
    }

    /** Cost of one entered unit at an average per base unit, rounded half up to the minor unit. */
    public static function costPerUnit(?string $avgPerBase, string $factor): ?int
    {
        if ($avgPerBase === null) {
            return null;
        }
        $v = bcmul($avgPerBase, $factor, 6);

        return (int) bcadd($v, bccomp($v, '0', 6) >= 0 ? '0.5' : '-0.5', 0);
    }
}
