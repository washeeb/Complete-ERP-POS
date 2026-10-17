<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Imports\Importer;
use App\Domain\Support\DomainException;
use App\Models\Barcode;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Warehouse;
use App\Support\Qty;
use Illuminate\Support\Facades\DB;

/** Shared look-ups for the stock imports, and the stock sheet (current stock standing) they can start from. */
final class StockRows
{
    /** A product by SKU, or by any of its barcodes. @return array{0: Product, 1: ?ProductUom} the unit when found by barcode */
    public static function product(array $row): array
    {
        $sku = strtoupper(trim((string) ($row['sku'] ?? '')));
        if ($sku !== '' && ($p = Product::where('sku', $sku)->first())) {
            return [$p, null];
        }
        $code = Importer::barcode((string) ($row['barcode'] ?? ''));
        if ($code !== '' && ($b = Barcode::where('code', $code)->first())) {
            return [Product::findOrFail($b->product_id), ProductUom::with('uom')->find($b->product_uom_id)];
        }
        throw new DomainException($sku !== '' || $code !== '' ? 'Unknown product '.($sku ?: $code).'.' : 'Give the SKU or the barcode.');
    }

    /** The unit named in the row (code), else the one found by barcode, else the base unit. */
    public static function unit(Product $product, array $row, ?ProductUom $fromBarcode = null): ProductUom
    {
        $code = strtoupper(trim((string) ($row['unit'] ?? '')));
        if ($code !== '') {
            return ProductUom::with('uom')->where('product_id', $product->id)->whereHas('uom', fn ($q) => $q->where('code', $code))->first()
                ?? throw new DomainException("{$product->sku} has no unit {$code}.");
        }

        return $fromBarcode ?? ProductUom::with('uom')->where('product_id', $product->id)->where('is_base', true)->firstOrFail();
    }

    public static function warehouse(string $code): Warehouse
    {
        return Warehouse::where('code', strtoupper(trim($code)))->first() ?? throw new DomainException("Unknown warehouse {$code}.");
    }

    /**
     * The stock standing of a warehouse: every stocked product with what the system holds now (base units), optionally
     * one category. Used for count sheets and "set to" adjustment files.
     *
     * @return \Generator<array{product: object, on_hand: string, barcode: ?string}>
     */
    public static function standing(Warehouse $warehouse, ?string $categoryId = null, bool $withZero = true): \Generator
    {
        $q = DB::table('products as p')->leftJoin('stock_levels as l', fn ($j) => $j->on('l.product_id', '=', 'p.id')->where('l.warehouse_id', $warehouse->id))
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')->leftJoin('uoms as u', 'u.id', '=', 'p.base_uom_id')
            ->where('p.tenant_id', $warehouse->tenant_id)->where('p.track_stock', true)
            ->where(fn ($w) => $w->where('p.is_active', true)->orWhere('l.on_hand', '<>', 0))
            ->when($categoryId, fn ($w) => $w->where('p.category_id', $categoryId))
            ->when(! $withZero, fn ($w) => $w->where('l.on_hand', '<>', 0))
            ->orderBy('c.name')->orderBy('p.name')
            ->select('p.id', 'p.sku', 'p.name', 'p.track_batches', 'p.track_serials', 'p.location', 'c.name as category', 'u.code as unit', 'l.on_hand', 'l.avg_cost_minor');
        foreach ($q->lazy(500) as $p) {
            $barcode = DB::table('barcodes as b')->join('product_uoms as pu', 'pu.id', '=', 'b.product_uom_id')->where('b.product_id', $p->id)->where('pu.is_base', true)->value('b.code');
            yield ['product' => $p, 'on_hand' => Qty::format(Qty::toMilli((string) ($p->on_hand ?? '0'))), 'barcode' => $barcode];
        }
    }

    /**
     * A product's batches in a warehouse that hold stock, earliest expiry first.
     *
     * @return list<object{batch_no: string, expires_on: ?string, on_hand: string}>
     */
    public static function batches(Warehouse $warehouse, string $productId): array
    {
        return DB::table('stock_batch_levels as bl')->join('stock_batches as b', 'b.id', '=', 'bl.batch_id')
            ->where('bl.warehouse_id', $warehouse->id)->where('bl.product_id', $productId)->where('bl.on_hand', '<>', 0)
            ->orderByRaw('b.expires_on nulls last')->orderBy('b.batch_no')->get(['b.batch_no', 'b.expires_on', 'bl.on_hand'])->all();
    }

    /** What one batch of a product holds in a warehouse (thousandths of the base unit), 0 when new. */
    public static function batchOnHand(string $warehouseId, string $productId, string $batchNo): int
    {
        return Qty::toMilli((string) (DB::table('stock_batch_levels as bl')->join('stock_batches as b', 'b.id', '=', 'bl.batch_id')
            ->where('bl.warehouse_id', $warehouseId)->where('bl.product_id', $productId)->where('b.batch_no', $batchNo)->value('bl.on_hand') ?? '0'));
    }

    /** Bin codes holding the product in the warehouse (for reference on sheets), when the warehouse uses bins. */
    public static function bins(Warehouse $warehouse, string $productId): string
    {
        return DB::table('stock_bin_levels as l')->join('bins as b', 'b.id', '=', 'l.bin_id')->where('l.warehouse_id', $warehouse->id)
            ->where('l.product_id', $productId)->where('l.on_hand', '<>', 0)->orderBy('b.code')->pluck('b.code')->implode(', ');
    }
}
