<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Imports\Importer;
use App\Domain\Inventory\StockCountService;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Barcode;
use App\Models\Bin;
use App\Models\StockCount;
use Illuminate\Database\Eloquent\Model;

/**
 * Counted quantities into an open stock count: download the count sheet, count (on paper, in Excel or on a phone),
 * fill counted_qty and import. Rows with no counted quantity are left uncounted (not set to zero). Importing again
 * replaces the earlier figure for the same product and bin. The count is then submitted and approved as usual.
 */
final class StockCountImporter extends Importer
{
    public function __construct(private readonly StockCountService $counts) {}

    public function key(): string
    {
        return 'stock_count';
    }

    public function label(): string
    {
        return 'Stock count results (counted quantities)';
    }

    public function description(): string
    {
        return 'Counted quantities for an open stock count, matched by SKU (or barcode) and bin. Empty counted_qty = not counted (left alone, not zero). '
            .'Importing again replaces the earlier figure. Start from the count sheet of that count. The count is then submitted and approved as usual, '
            .'and the differences become stock movements.';
    }

    public function permission(): string
    {
        return 'stock.count';
    }

    public function contextType(): ?string
    {
        return 'stock_count';
    }

    public function columns(): array
    {
        return [
            ['name' => 'sku', 'required' => true, 'help' => 'Product SKU (or leave empty and give the barcode).', 'example' => 'RICE-5KG'],
            ['name' => 'counted_qty', 'required' => true, 'help' => 'What you counted, in the unit below. Empty = not counted.', 'example' => '38'],
            ['name' => 'unit', 'required' => false, 'help' => 'Unit code. Empty = base unit (or the unit of the barcode).', 'example' => 'PC'],
            ['name' => 'bin', 'required' => false, 'help' => 'Bin code (warehouses with bins).', 'example' => ''],
            ['name' => 'barcode', 'required' => false, 'help' => 'Barcode (instead of the SKU).', 'example' => ''],
            ['name' => 'name', 'required' => false, 'help' => 'For information only (ignored).', 'example' => 'Perfumed rice 5 kg'],
            ['name' => 'expected_qty', 'required' => false, 'help' => 'For information only — empty on blind counts (ignored).', 'example' => ''],
        ];
    }

    public function apply(Actor $actor, array $row, array $options, ?Model $context): string
    {
        if (! $context instanceof StockCount) {
            throw new DomainException('Choose the stock count the file belongs to.');
        }
        if (! self::has($row, 'counted_qty')) {
            return 'unchanged';
        }
        [$product, $fromBarcode] = StockRows::product($row);
        $unit = StockRows::unit($product, $row, $fromBarcode);
        $binId = self::has($row, 'bin') ? (Bin::where('warehouse_id', $context->warehouse_id)->where('code', strtoupper($row['bin']))->value('id')
            ?? throw new DomainException("Unknown bin {$row['bin']} in this warehouse.")) : null;
        $this->counts->record($actor, $context, $product, $unit, self::number($row['counted_qty'], 'counted_qty'), $binId);

        return 'added';
    }

    /** The count sheet of this count (expected quantities only on counts that are not blind). */
    public function exportRows(Actor $actor, array $options, ?Model $context): ?iterable
    {
        if (! $context instanceof StockCount) {
            return null;
        }

        return (function () use ($context) {
            foreach ($context->lines()->with('product.baseUom', 'bin')->get()->sortBy(fn ($l) => ($l->bin?->code ?? '').'|'.$l->product->name) as $l) {
                $barcode = Barcode::where('product_id', $l->product_id)->whereHas('productUom', fn ($q) => $q->where('is_base', true))->value('code');
                yield [$l->product->sku, $l->counted_qty === null ? '' : rtrim(rtrim((string) $l->counted_qty, '0'), '.'), $l->product->baseUom?->code, $l->bin?->code,
                    $barcode, $l->product->name, $context->blind ? '' : rtrim(rtrim((string) $l->expected_qty, '0'), '.')];
            }
        })();
    }
}
