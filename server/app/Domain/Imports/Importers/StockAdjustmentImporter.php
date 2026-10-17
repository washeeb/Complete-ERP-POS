<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Imports\Importer;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockAdjustmentService;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\Warehouse;
use App\Support\Qty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Stock adjustment lines from a file: into a draft adjustment, or a new one for a warehouse and reason. Each row is an
 * increase, a decrease or "set to" a new quantity. Starting from the stock sheet (current stock standing) and typing
 * the new quantities is the quick way to update stock from a count done on paper or in Excel; rows whose quantity did
 * not change are skipped. The adjustment then goes through approval and posting as usual.
 */
final class StockAdjustmentImporter extends Importer
{
    public const MODES = ['add' => 'add', 'increase' => 'add', 'in' => 'add', '+' => 'add', 'plus' => 'add',
        'remove' => 'remove', 'decrease' => 'remove', 'out' => 'remove', '-' => 'remove', 'minus' => 'remove',
        'set' => 'set', 'set_to' => 'set', 'set to' => 'set', 'count' => 'set', 'counted' => 'set', '=' => 'set'];

    public function __construct(private readonly StockAdjustmentService $adjustments) {}

    public function key(): string
    {
        return 'stock_adjustment';
    }

    public function label(): string
    {
        return 'Stock adjustment lines (increase, decrease, set to new quantity)';
    }

    public function description(): string
    {
        return 'Adds lines to a draft stock adjustment (or a new one for the warehouse and reason chosen). mode: increase, decrease or set (the new quantity on hand). '
            .'With mode empty, a quantity with a minus sign is a decrease and any other an increase. Tip: download the stock sheet (current stock standing), type the new '
            .'quantities in the qty column and import it — rows that did not change are skipped. Batch-tracked products have one row per batch with its expiry date: '
            .'set the batch\'s new quantity, add a row for a new batch (its number and expiry), or change a batch\'s expiry date to correct it. Serial-numbered products '
            .'need increase/decrease with their serial numbers. Nothing changes in stock until the adjustment is approved and posted (a corrected expiry date applies at once).';
    }

    public function permission(): string
    {
        return 'stock.adjust';
    }

    public function contextType(): ?string
    {
        return 'stock_adjustment';
    }

    public function columns(): array
    {
        return [
            ['name' => 'sku', 'required' => true, 'help' => 'Product SKU (or leave empty and give the barcode).', 'example' => 'RICE-5KG'],
            ['name' => 'mode', 'required' => false, 'help' => 'increase, decrease or set. Empty = sign of qty.', 'example' => 'set'],
            ['name' => 'qty', 'required' => true, 'help' => 'Quantity to add or remove, or (set) the new quantity on hand. Empty = skip the row.', 'example' => '38'],
            ['name' => 'unit', 'required' => false, 'help' => 'Unit code. Empty = base unit.', 'example' => 'PC'],
            ['name' => 'unit_cost', 'required' => false, 'help' => 'Increases: cost of one unit coming in. Empty = current average cost.', 'example' => ''],
            ['name' => 'batch_no', 'required' => false, 'help' => 'Batch number (batch-tracked products: one row per batch).', 'example' => ''],
            ['name' => 'expires_on', 'required' => false, 'help' => 'Expiry date of that batch: needed for a new batch; a different date on an existing batch corrects it.', 'example' => ''],
            ['name' => 'serials', 'required' => false, 'help' => 'Serial / IMEI numbers, one per unit, separated by |.', 'example' => ''],
            ['name' => 'reason', 'required' => false, 'help' => 'Reason code for this line if different: '.implode(', ', array_keys(InventoryService::ADJUSTMENT_REASONS)).'.', 'example' => ''],
            ['name' => 'note', 'required' => false, 'help' => 'Note on the line.', 'example' => ''],
            ['name' => 'barcode', 'required' => false, 'help' => 'Barcode (instead of the SKU).', 'example' => ''],
            ['name' => 'name', 'required' => false, 'help' => 'For information only (ignored).', 'example' => 'Perfumed rice 5 kg'],
            ['name' => 'on_hand', 'required' => false, 'help' => 'For information only: the system quantity when the sheet was made (ignored).', 'example' => '40'],
            ['name' => 'location', 'required' => false, 'help' => 'For information only: where the product is kept (change it on the product).', 'example' => ''],
            ['name' => 'bins', 'required' => false, 'help' => 'For information only: the bins holding it, in a warehouse that uses bins (move stock between bins on the Bins screen).', 'example' => ''],
        ];
    }

    public function optionDefaults(): array
    {
        return ['warehouse_id' => null, 'reason_code' => 'correction'];
    }

    /** The rows build one document together (prepare/finish): the whole file stays in one transaction. */
    public function inBatches(): bool
    {
        return false;
    }

    public function prepare(Actor $actor, array $options, ?Model $context): ?Model
    {
        if ($context) {
            return $context;
        }
        $warehouse = Warehouse::find($options['warehouse_id'] ?? null) ?? throw new DomainException('Choose the adjustment to add to, or the warehouse for a new one.');

        return $this->adjustments->create($actor, $warehouse, ['reason_code' => $options['reason_code'] ?? 'correction', 'note' => 'Imported from a CSV file']);
    }

    public function apply(Actor $actor, array $row, array $options, ?Model $context): string
    {
        /** @var StockAdjustment $context */
        if (! self::has($row, 'qty')) {
            return 'unchanged';
        }
        [$product, $fromBarcode] = StockRows::product($row);
        $unit = StockRows::unit($product, $row, $fromBarcode);
        $qty = self::number($row['qty'], 'qty');
        $modeText = strtolower(trim((string) ($row['mode'] ?? '')));
        $mode = $modeText === '' ? (str_starts_with($qty, '-') ? 'remove' : 'add') : (self::MODES[$modeText] ?? throw new DomainException("mode: write increase, decrease or set (not \"{$row['mode']}\")."));
        $qty = ltrim($qty, '+-');
        if ($mode === 'set' && $product->track_batches) {
            return $this->setBatch($actor, $context, $product, $unit, $qty, $row);
        }
        if ($mode === 'set') {
            $onHand = Qty::toMilli((string) (DB::table('stock_levels')->where('warehouse_id', $context->warehouse_id)->where('product_id', $product->id)->value('on_hand') ?? '0'));
            if (Qty::toBaseMilli(Qty::toMilli($qty), Qty::factorMicro($unit->factor)) === $onHand) {
                return 'unchanged'; // the count matches the system: nothing to adjust
            }
        }
        $this->adjustments->addLine($actor, $context, ['product_id' => $product->id, 'product_uom_id' => $unit->id, 'mode' => $mode, 'qty' => $qty,
            'unit_cost' => $row['unit_cost'] ?? null, 'batch_no' => $row['batch_no'] ?? null,
            'expires_on' => self::has($row, 'expires_on') ? substr(self::date($row['expires_on'], 'expires_on'), 0, 10) : null,
            'serials' => $row['serials'] ?? null, 'reason_code' => $row['reason'] ?? null, 'note' => $row['note'] ?? null]);

        return 'added';
    }

    /**
     * "Set" for a batch-tracked product: the batch's new quantity becomes an increase or decrease of the difference with
     * what the batch holds now (worked out at the import), and a different expiry date on an existing batch corrects it.
     */
    private function setBatch(Actor $actor, StockAdjustment $adjustment, Product $product, ProductUom $unit, string $qty, array $row): string
    {
        $batchNo = trim((string) ($row['batch_no'] ?? ''));
        if ($batchNo === '') {
            throw new DomainException("{$product->sku}: tracks batches — give the batch number on each row.");
        }
        $expires = self::has($row, 'expires_on') ? substr(self::date($row['expires_on'], 'expires_on'), 0, 10) : null;
        $outcome = 'unchanged';
        $batch = StockBatch::where('product_id', $product->id)->where('batch_no', $batchNo)->first();
        if ($batch && $expires && $batch->expires_on?->toDateString() !== $expires) {
            app(InventoryService::class)->correctBatchExpiry($actor, $batch, $expires);
            $outcome = 'updated';
        }
        $now = StockRows::batchOnHand($adjustment->warehouse_id, $product->id, $batchNo);
        $diff = Qty::toBaseMilli(Qty::toMilli($qty), Qty::factorMicro($unit->factor)) - $now;
        if ($diff === 0) {
            return $outcome;
        }
        if (! $batch && ! $expires) {
            throw new DomainException("{$product->sku}: batch {$batchNo} is new — give its expiry date.");
        }
        $base = ProductUom::with('uom')->where('product_id', $product->id)->where('is_base', true)->firstOrFail();
        $this->adjustments->addLine($actor, $adjustment, ['product_id' => $product->id, 'product_uom_id' => $base->id, 'mode' => $diff > 0 ? 'add' : 'remove',
            'qty' => Qty::format(abs($diff)), 'unit_cost' => $row['unit_cost'] ?? null, 'batch_no' => $batchNo, 'expires_on' => $expires ?? $batch?->expires_on?->toDateString(),
            'reason_code' => $row['reason'] ?? null, 'note' => trim('Counted '.$qty.' '.$unit->uom->code.' of batch '.$batchNo.'. '.($row['note'] ?? ''))]);

        return 'added';
    }

    /** The stock sheet: current stock standing of the warehouse, ready for "set" quantities. */
    public function exportRows(Actor $actor, array $options, ?Model $context): ?iterable
    {
        $warehouse = $context?->warehouse ?? Warehouse::find($options['warehouse_id'] ?? null);
        if (! $warehouse) {
            return null;
        }

        return (function () use ($warehouse, $options) {
            foreach (StockRows::standing($warehouse, $options['category_id'] ?? null) as $s) {
                $p = $s['product'];
                $bins = $warehouse->use_bins ? StockRows::bins($warehouse, $p->id) : '';
                if ($p->track_serials) {
                    yield [$p->sku, '', '', $p->unit, '', '', '', '', '', 'Serial-numbered: use increase / decrease with the serial numbers', $s['barcode'], $p->name, $s['on_hand'], $p->location, $bins];
                } elseif ($p->track_batches) {
                    // One row per batch with its expiry; a product without batches yet gets one row to fill in.
                    $batches = StockRows::batches($warehouse, $p->id);
                    foreach ($batches ?: [null] as $b) {
                        $held = $b ? Qty::format(Qty::toMilli((string) $b->on_hand)) : '0';
                        yield [$p->sku, 'set', $held, $p->unit, '', $b->batch_no ?? '', $b?->expires_on ? substr($b->expires_on, 0, 10) : '', '', '', $b ? '' : 'No batches yet: type the batch number and expiry',
                            $s['barcode'], $p->name, $held, $p->location, $bins];
                    }
                } else {
                    yield [$p->sku, 'set', $s['on_hand'], $p->unit, '', '', '', '', '', '', $s['barcode'], $p->name, $s['on_hand'], $p->location, $bins];
                }
            }
        })();
    }
}
