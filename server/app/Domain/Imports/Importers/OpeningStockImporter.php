<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Imports\Importer;
use App\Domain\Inventory\InventoryService;
use App\Domain\Support\Actor;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Warehouse;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
        return 'One row per product and warehouse: the quantity counted at go-live and its cost per unit (used for stock value and cost of sales). The product is found by SKU, barcode or exact name. Rows with no quantity are skipped. '
            .'No cost: the product\'s cost on file (its average cost here, or its supplier\'s last price). Batch-tracked products: one row per batch with its expiry. '
            .'Already sold at the till before the count was loaded: still accepted (stock becomes the count less what was sold since). A product already received, adjusted or moved in that warehouse is refused — use a stock adjustment.';
    }

    public function permission(): string
    {
        return 'stock.adjust';
    }

    public function columns(): array
    {
        return [
            ['name' => 'warehouse', 'required' => true, 'help' => 'Warehouse code (Company → Branches → Warehouses).', 'example' => 'HQ-MAIN'],
            ['name' => 'sku', 'required' => false, 'help' => 'Product SKU. Or leave it empty and give the barcode, or the exact product name.', 'example' => 'RICE-5KG'],
            ['name' => 'barcode', 'required' => false, 'help' => 'Barcode, instead of the SKU. Format the column as Text in Excel.', 'example' => ''],
            ['name' => 'name', 'required' => false, 'help' => 'Exact product name, for products with neither SKU nor barcode yet (it must match one product).', 'example' => ''],
            ['name' => 'qty', 'required' => true, 'help' => 'Quantity on hand, in the unit below.', 'example' => '40'],
            ['name' => 'unit', 'required' => false, 'help' => 'Unit code. Empty = base unit.', 'example' => 'PC'],
            ['name' => 'unit_cost', 'required' => false, 'help' => 'Cost of one unit (what you paid, before VAT you can reclaim). Empty = the product\'s cost on file.', 'example' => '95.00'],
            ['name' => 'batch_no', 'required' => false, 'help' => 'Batch number (batch-tracked products).', 'example' => ''],
            ['name' => 'expires_on', 'required' => false, 'help' => 'Expiry date of the batch.', 'example' => ''],
        ];
    }

    /** No cost on the row (owner 2026-11-15): the average cost held here for it, else its supplier's last price; null if neither. */
    private static function costOnFile(Product $product, ProductUom $unit, Warehouse $warehouse): ?string
    {
        $exp = Money::exponent($warehouse->tenant?->currency_code ?? 'GHS');
        $avg = DB::table('stock_levels')->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('avg_cost_minor');
        if ($avg !== null && (float) $avg > 0) {
            return number_format((float) $avg * (float) $unit->factor / 10 ** $exp, $exp, '.', '');
        }
        $last = DB::table('supplier_products')->where('product_id', $product->id)->whereNotNull('last_cost_minor')
            ->orderByDesc('id')->first(['last_cost_minor', 'product_uom_id']);
        if (! $last) {
            return null;
        }
        $lastFactor = $last->product_uom_id ? (float) (ProductUom::find($last->product_uom_id)?->factor ?? 1) : 1.0;

        return number_format($last->last_cost_minor / max($lastFactor, 0.000001) * (float) $unit->factor / 10 ** $exp, $exp, '.', '');
    }

    public function reference(array $row): string
    {
        return trim(($row['warehouse'] ?? '').' '.(($row['sku'] ?? '') ?: (($row['barcode'] ?? '') ?: ($row['name'] ?? ''))));
    }

    public function apply(Actor $actor, array $row, array $options, ?Model $context): string
    {
        // A count sheet lists every product; one not counted (or counted as none) has nothing to open with.
        if (! self::has($row, 'qty') || (float) self::number($row['qty'], 'qty') == 0.0) {
            return 'unchanged';
        }
        $warehouse = StockRows::warehouse(self::need($row, 'warehouse', 'the warehouse'));
        [$product, $fromBarcode] = StockRows::productOrName($row, $warehouse);
        $unit = StockRows::unit($product, $row, $fromBarcode);
        $cost = self::has($row, 'unit_cost') && (float) self::number($row['unit_cost'], 'unit_cost') != 0.0 ? self::number($row['unit_cost'], 'unit_cost') : self::costOnFile($product, $unit, $warehouse);
        $this->inventory->adjust($actor, $warehouse, $product, $unit, self::number(self::need($row, 'qty', 'the quantity'), 'qty'), 'opening', '', 'CSV import',
            $cost, $row['batch_no'] ?? null,
            self::has($row, 'expires_on') ? substr(self::date($row['expires_on'], 'expires_on'), 0, 10) : null);

        return 'added';
    }
}
