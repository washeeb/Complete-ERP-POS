<?php

namespace App\Domain\Inventory;

use Illuminate\Support\Facades\DB;

/**
 * Every stock-tracked product has a stock row (0 until something moves) in each store of its industry — a shared
 * product in every store — so Stock on hand, reorder levels and adjustments list it from the day it is created, not
 * only after its first movement. Rows are added, never removed; "has stock moved" is answered by the movements.
 */
final class ZeroStock
{
    /**
     * @param  list<string>|null  $productIds  null = every product of the company
     * @param  list<string>|null  $warehouseIds  null = every store
     */
    public static function ensure(string $tenantId, ?array $productIds = null, ?array $warehouseIds = null): int
    {
        if ($productIds === [] || $warehouseIds === []) {
            return 0;
        }
        $ids = fn (?array $list, string $col) => $list === null ? '' : " AND {$col} IN (".implode(',', array_fill(0, count($list), '?')).')';
        $sql = 'INSERT INTO stock_levels (tenant_id, warehouse_id, product_id, on_hand, updated_at)
            SELECT p.tenant_id, w.id, p.id, 0, now()
            FROM products p
            JOIN warehouses w ON w.tenant_id = p.tenant_id AND w.is_active
            JOIN branches b ON b.id = w.branch_id
            WHERE p.tenant_id = ? AND p.track_stock
              AND (p.industry_id IS NULL OR b.industry_id IS NULL OR b.industry_id = p.industry_id)'
            .$ids($productIds, 'p.id').$ids($warehouseIds, 'w.id').'
            ON CONFLICT (warehouse_id, product_id) DO NOTHING';

        return DB::affectingStatement($sql, [$tenantId, ...($productIds ?? []), ...($warehouseIds ?? [])]);
    }
}
