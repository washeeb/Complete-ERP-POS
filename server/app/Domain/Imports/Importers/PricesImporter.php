<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Catalog\CatalogService;
use App\Domain\Imports\Importer;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Price;
use App\Models\PriceList;
use App\Models\Product;
use App\Support\Money;
use App\Support\Qty;
use Illuminate\Database\Eloquent\Model;

/**
 * Prices (price list update): one row per price — product, list, unit, minimum quantity and optional dates. A row with the
 * same product, list, unit, minimum quantity and dates as an existing price replaces it; anything else adds a price
 * (bulk tiers, promotions with dates). Other prices of the product stay as they are.
 */
final class PricesImporter extends Importer
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function key(): string
    {
        return 'prices';
    }

    public function label(): string
    {
        return 'Prices (price list update)';
    }

    public function description(): string
    {
        return 'One row per price. A row for the same product, price list, unit, minimum quantity and dates replaces that price; other rows add bulk prices (min_qty) or dated promotional prices. '
            .'Other prices are not touched. Needs the pricing permission.';
    }

    public function permission(): string
    {
        return 'pricing.manage';
    }

    public function columns(): array
    {
        return [
            ['name' => 'sku', 'required' => true, 'help' => 'Product SKU.', 'example' => 'RICE-5KG'],
            ['name' => 'price_list', 'required' => true, 'help' => 'Price list code (Pricing & tax → Price lists).', 'example' => 'RETAIL'],
            ['name' => 'price', 'required' => true, 'help' => 'The price for one unit, as on the list (with or without tax as the list says).', 'example' => '118.50'],
            ['name' => 'unit', 'required' => false, 'help' => 'Unit code (PC, CTN…). Empty = the base unit.', 'example' => 'PC'],
            ['name' => 'min_qty', 'required' => false, 'help' => 'From this quantity (bulk price). Empty = 1.', 'example' => '1'],
            ['name' => 'starts_at', 'required' => false, 'help' => 'Promotional price from (YYYY-MM-DD or DD/MM/YYYY, optional time).', 'example' => ''],
            ['name' => 'ends_at', 'required' => false, 'help' => 'Promotional price until.', 'example' => ''],
            ['name' => 'name', 'required' => false, 'help' => 'For information only (ignored).', 'example' => 'Perfumed rice 5 kg'],
        ];
    }

    public function reference(array $row): string
    {
        return trim(($row['sku'] ?? '').' '.($row['price_list'] ?? ''));
    }

    public function apply(Actor $actor, array $row, array $options, ?Model $context): string
    {
        $product = Product::where('sku', strtoupper(self::need($row, 'sku', 'the SKU')))->first() ?? throw new DomainException("Unknown SKU {$row['sku']}.");
        $list = PriceList::where('code', strtoupper(self::need($row, 'price_list', 'the price list')))->first() ?? throw new DomainException("Unknown price list {$row['price_list']}.");
        $amount = self::number(self::need($row, 'price', 'the price'), 'price');
        if ((float) $amount < 0) {
            throw new DomainException('A price cannot be negative.');
        }
        $uomId = self::has($row, 'unit') ? (self::unitId($row['unit']) ?? throw new DomainException("Unknown unit {$row['unit']}.")) : $product->base_uom_id;
        if (! $product->units()->where('uom_id', $uomId)->exists()) {
            throw new DomainException("{$product->sku} is not sold in {$row['unit']}. Add the unit to the product first.");
        }
        $min = self::has($row, 'min_qty') ? self::number($row['min_qty'], 'min_qty') : '1';
        $starts = self::has($row, 'starts_at') ? self::date($row['starts_at'], 'starts_at') : null;
        $ends = self::has($row, 'ends_at') ? self::date($row['ends_at'], 'ends_at') : null;
        $input = CatalogService::inputOf($product);
        $before = count($input['prices']);
        $existing = $product->prices()->where('price_list_id', $list->id)->whereHas('productUom', fn ($q) => $q->where('uom_id', $uomId))
            ->where('min_qty', Qty::format(Qty::toMilli($min)))->where('starts_at', $starts)->where('ends_at', $ends)->first();
        if ($existing && $existing->amount_minor === Money::toMinor($amount, $list->currency_code)) {
            return 'unchanged';
        }
        $prices = CatalogService::withPrice($input['prices'], $list->id, $uomId, $min, $amount, $starts, $ends);
        $this->catalog->saveProduct($actor, $product, ['prices' => $prices], 'CSV price import');

        return count($prices) > $before ? 'added' : 'updated';
    }

    public function exportRows(Actor $actor, array $options, ?Model $context): iterable
    {
        foreach (Price::with(['product', 'priceList', 'productUom.uom'])->join('products', 'products.id', '=', 'prices.product_id')
            ->orderBy('products.sku')->select('prices.*')->lazy(500) as $p) {
            yield [$p->product->sku, $p->priceList->code, Money::format($p->amount_minor, $p->priceList->currency_code), $p->productUom->uom->code,
                rtrim(rtrim((string) $p->min_qty, '0'), '.'), $p->starts_at?->toDateTimeString(), $p->ends_at?->toDateTimeString(), $p->product->name];
        }
    }
}
