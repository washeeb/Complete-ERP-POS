<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Imports\Importer;
use App\Domain\Inventory\InventoryService;
use App\Domain\Support\Actor;
use Illuminate\Database\Eloquent\Model;

/**
 * Opening stock (go-live): the first quantity and cost of each product in each warehouse. A product that already has
 * stock history in that warehouse is refused — use a stock adjustment instead.
 */
final class OpeningStockImporter extends Importer
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function key(): string
    {
        return 'opening_stock';
    }

    public function label(): string
    {
        return 'Opening stock (go-live balances)';
    }

    public function description(): string
    {
        return 'One row per product and warehouse: the quantity counted at go-live and its cost per unit (used for stock value and cost of sales). '
            .'Batch-tracked products: one row per batch with its expiry. A product that already has stock history in that warehouse is refused — use a stock adjustment.';
    }

    public function permission(): string
    {
        return 'stock.adjust';
    }

    public function columns(): array
    {
        return [
            ['name' => 'warehouse', 'required' => true, 'help' => 'Warehouse code (Company → Branches → Warehouses).', 'example' => 'HQ-MAIN'],
            ['name' => 'sku', 'required' => true, 'help' => 'Product SKU.', 'example' => 'RICE-5KG'],
            ['name' => 'qty', 'required' => true, 'help' => 'Quantity on hand, in the unit below.', 'example' => '40'],
            ['name' => 'unit', 'required' => false, 'help' => 'Unit code. Empty = base unit.', 'example' => 'PC'],
            ['name' => 'unit_cost', 'required' => false, 'help' => 'Cost of one unit (what you paid, before VAT you can reclaim).', 'example' => '95.00'],
            ['name' => 'batch_no', 'required' => false, 'help' => 'Batch number (batch-tracked products).', 'example' => ''],
            ['name' => 'expires_on', 'required' => false, 'help' => 'Expiry date of the batch.', 'example' => ''],
        ];
    }

    public function reference(array $row): string
    {
        return trim(($row['warehouse'] ?? '').' '.($row['sku'] ?? ''));
    }

    public function apply(Actor $actor, array $row, array $options, ?Model $context): string
    {
        $warehouse = StockRows::warehouse(self::need($row, 'warehouse', 'the warehouse'));
        [$product] = StockRows::product($row);
        $unit = StockRows::unit($product, $row);
        $this->inventory->adjust($actor, $warehouse, $product, $unit, self::number(self::need($row, 'qty', 'the quantity'), 'qty'), 'opening', '', 'CSV import',
            self::has($row, 'unit_cost') ? self::number($row['unit_cost'], 'unit_cost') : null, $row['batch_no'] ?? null,
            self::has($row, 'expires_on') ? substr(self::date($row['expires_on'], 'expires_on'), 0, 10) : null);

        return 'added';
    }
}
