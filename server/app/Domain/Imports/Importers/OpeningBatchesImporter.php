<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Imports\Importer;
use App\Domain\Inventory\InventoryService;
use App\Domain\Support\Actor;
use App\Support\Qty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The expiry sheet (owner request 2026-11-07): stock with no real batch, which got none of the expiry warnings and sold
 * after everything dated. Download the list, fill in what the shelf says, upload it. Quantities and value never change:
 * stock only moves out of batch OPENING. Rows with no date are left for later, so it can be done a shelf at a time.
 */
final class OpeningBatchesImporter extends Importer
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function key(): string
    {
        return 'opening_batches';
    }

    public function label(): string
    {
        return 'Expiry sheet (stock with no batch or expiry date)';
    }

    public function description(): string
    {
        return 'Download your current data for the list of stock in batch OPENING, fill in the expiry date (and batch number and quantity if the shelf has several), and upload it. One row per batch: copy a row for a second batch. '
            .'A date with no batch number dates all that is left in OPENING for this product. Rows with no date are left as they are. Stock and value do not change.';
    }

    public function permission(): string
    {
        return 'stock.adjust';
    }

    public function columns(): array
    {
        return [
            ['name' => 'warehouse', 'required' => true, 'help' => 'Warehouse code. Already filled in on the downloaded list.', 'example' => 'HQ-MAIN'],
            ['name' => 'sku', 'required' => true, 'help' => 'Product SKU. Already filled in on the downloaded list.', 'example' => 'PHARMA-000482'],
            ['name' => 'product', 'required' => false, 'help' => 'For your eyes only: not read.', 'example' => 'CYTOTEC 200MCG'],
            ['name' => 'no_batch_qty', 'required' => false, 'help' => 'How much has no batch yet, in the smallest unit. For your eyes only: not read.', 'example' => '120'],
            ['name' => 'expires_on', 'required' => false, 'help' => 'Expiry date from the pack. Empty = leave this row for later.', 'example' => '2029-02-28'],
            ['name' => 'batch_no', 'required' => false, 'help' => 'Batch or lot number. Empty = date all that is left in OPENING.', 'example' => '835886'],
            ['name' => 'qty', 'required' => false, 'help' => 'How much of this batch, in the smallest unit. Empty = all that is left in OPENING.', 'example' => '60'],
        ];
    }

    public function reference(array $row): string
    {
        return trim(($row['warehouse'] ?? '').' '.($row['sku'] ?? '').' '.($row['batch_no'] ?? ''));
    }

    public function apply(Actor $actor, array $row, array $options, ?Model $context): string
    {
        if (! self::has($row, 'expires_on')) {
            return 'unchanged';
        }
        $warehouse = StockRows::warehouse(self::need($row, 'warehouse', 'the warehouse'));
        [$product] = StockRows::product($row);
        $this->inventory->dateOpeningStock($actor, $warehouse, $product, substr(self::date($row['expires_on'], 'expires_on'), 0, 10), trim((string) ($row['batch_no'] ?? '')),
            self::has($row, 'qty') ? self::number($row['qty'], 'qty') : null);

        return 'updated';
    }

    /** The list to fill in: what is still in batch OPENING, store by store. */
    public function exportRows(Actor $actor, array $options, ?Model $context): ?iterable
    {
        return DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')->join('products as p', 'p.id', '=', 'l.product_id')->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->where('l.tenant_id', $actor->tenantId)->where('b.batch_no', 'OPENING')->whereNull('b.expires_on')->where('l.on_hand', '>', 0)->orderBy('w.code')->orderBy('p.name')
            ->get(['w.code', 'p.sku', 'p.name', 'l.on_hand'])
            ->map(fn ($r) => ['warehouse' => $r->code, 'sku' => $r->sku, 'product' => $r->name, 'no_batch_qty' => rtrim(rtrim(Qty::format(Qty::toMilli((string) $r->on_hand)), '0'), '.'), 'expires_on' => '', 'batch_no' => '', 'qty' => '']);
    }
}
