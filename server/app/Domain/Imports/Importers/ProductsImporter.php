<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Catalog\CatalogService;
use App\Domain\Imports\Importer;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Brand;
use App\Models\Category;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\TaxGroup;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Products: create new ones and update existing ones, matched by SKU. Blank cells leave the current value unchanged,
 * so a file can update just the columns it has (e.g. only names, or only retail prices). Prices go in columns named
 * price_<list code> (price_RETAIL, price_WHOLESALE): the base-unit price from 1 piece, without dates.
 */
final class ProductsImporter extends Importer
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function key(): string
    {
        return 'products';
    }

    public function label(): string
    {
        return 'Products (create and update)';
    }

    public function description(): string
    {
        return 'One row per product, matched by SKU: a new SKU creates the product, an existing SKU updates it. Empty cells leave that detail as it is. '
            .'Category and brand are matched by name (and created if "Create missing categories and brands" is on). Barcodes: several separated by |. '
            .'Prices: a column per price list, named price_ followed by the list code (e.g. price_RETAIL); they set the price of one base unit. '
            .'Changing prices needs the pricing permission.';
    }

    public function permission(): string
    {
        return 'catalog.manage';
    }

    public function columns(): array
    {
        $cols = [
            ['name' => 'sku', 'required' => false, 'help' => 'Leave empty for a new product: the system gives it an SKU. To change a product, use its SKU (download the products first). Codes from your previous system are kept so their stock and prices can follow.', 'example' => ''],
            ['name' => 'name', 'required' => 'new', 'help' => 'Product name as shown on receipts.', 'example' => 'Perfumed rice 5 kg'],
            ['name' => 'description', 'required' => false, 'help' => 'Longer description (online shop).', 'example' => ''],
            ['name' => 'category', 'required' => false, 'help' => 'Category name.', 'example' => 'Groceries'],
            ['name' => 'brand', 'required' => false, 'help' => 'Brand name.', 'example' => 'Royal Aroma'],
            ['name' => 'tax_group', 'required' => false, 'help' => 'Tax group code (Pricing & tax → Tax groups). New products: STD if empty.', 'example' => 'STD'],
            ['name' => 'base_unit', 'required' => false, 'help' => 'Unit stock is counted in: its code or name (PC, KG, TAB or Tablet). New products: PC if empty. Cannot change once stock has moved.', 'example' => 'PC'],
            ['name' => 'track_stock', 'required' => false, 'help' => 'yes / no. New products: yes if empty.', 'example' => 'yes'],
            ['name' => 'track_batches', 'required' => false, 'help' => 'yes / no — batches and expiry dates.', 'example' => 'no'],
            ['name' => 'for_sale', 'required' => false, 'help' => 'yes / no — no takes it off the tills and shop.', 'example' => 'yes'],
            ['name' => 'plu', 'required' => false, 'help' => 'Scale code for weighed items (up to 5 digits).', 'example' => ''],
            ['name' => 'barcodes', 'required' => false, 'help' => 'Barcodes of one base unit, separated by |. Replaces the current base-unit barcodes. In Excel, format this column as Text first, or Excel drops leading zeros and shortens long numbers.', 'example' => '6001067000123'],
        ];
        foreach (PriceList::where('is_active', true)->orderByDesc('is_default')->orderBy('code')->get() as $list) {
            $cols[] = ['name' => 'price_'.strtolower($list->code), 'required' => false,
                'help' => "Price on the {$list->name} list for one base unit (".($list->prices_include_tax ? 'including' : 'excluding').' tax).', 'example' => $list->is_default ? '120.00' : ''];
        }

        return $cols;
    }

    public function acceptsColumn(string $heading): bool
    {
        return str_starts_with($heading, 'price_');
    }

    public function optionDefaults(): array
    {
        return ['create_missing' => true];
    }

    public function apply(Actor $actor, array $row, array $options, ?Model $context): string
    {
        $sku = self::has($row, 'sku') ? strtoupper(trim((string) $row['sku'])) : '';
        $product = $sku !== '' ? Product::where('sku', $sku)->first() : null;
        $isNew = ! $product;
        // Empty: the system gives the new product its SKU. A code brought from the previous system is kept, so that its
        // prices and opening stock (imported next, by that code) find it; codes stay unique either way.
        $data = $isNew && $sku !== '' ? ['sku' => $sku] : [];
        if ($isNew) {
            $data['name'] = self::need($row, 'name', 'the name (new product)');
        } elseif (self::has($row, 'name')) {
            $data['name'] = $row['name'];
        }
        if (self::has($row, 'description')) {
            $data['description'] = $row['description'];
        }
        foreach (['category' => Category::class, 'brand' => Brand::class] as $col => $class) {
            if (self::has($row, $col)) {
                $data[$col.'_id'] = $this->named($actor, $class, $row[$col], ! empty($options['create_missing']), $col);
            }
        }
        if (self::has($row, 'tax_group') || $isNew) {
            $code = strtoupper(self::has($row, 'tax_group') ? $row['tax_group'] : 'STD');
            $data['tax_group_id'] = TaxGroup::where('code', $code)->value('id') ?? throw new DomainException("Unknown tax group {$code}.");
        }
        if (self::has($row, 'base_unit') || $isNew) {
            $code = self::has($row, 'base_unit') ? $row['base_unit'] : 'PC';
            $data['base_uom_id'] = self::unitId($code) ?? throw new DomainException("Unknown unit {$code} (Catalog → Units of measure).");
        }
        $data['track_stock'] = self::has($row, 'track_stock') ? self::bool($row['track_stock'], 'track_stock') : ($isNew ? true : $product->track_stock);
        foreach (['track_batches' => 'track_batches', 'for_sale' => 'is_active'] as $col => $field) {
            if (self::has($row, $col)) {
                $data[$field] = self::bool($row[$col], $col);
            }
        }
        if ($isNew) {
            $data['is_active'] ??= true;
        }
        if (self::has($row, 'plu')) {
            $data['plu'] = self::barcode($row['plu']);
        }
        $current = $product ? CatalogService::inputOf($product) : ['units' => [], 'base_barcodes' => [], 'prices' => []];
        if (self::has($row, 'barcodes')) {
            $data['base_barcodes'] = array_map([self::class, 'barcode'], self::list($row['barcodes']));
            $data['units'] = $current['units'];
        } elseif ($isNew) {
            $data['base_barcodes'] = [];
        }
        $prices = $current['prices'];
        $priced = false;
        foreach ($row as $col => $value) {
            if (! str_starts_with($col, 'price_') || trim((string) $value) === '') {
                continue;
            }
            $list = PriceList::where('code', strtoupper(substr($col, 6)))->first() ?? throw new DomainException('Unknown price list '.strtoupper(substr($col, 6)).'.');
            $amount = self::number($value, $col);
            $prices = CatalogService::withPrice($prices, $list->id, $data['base_uom_id'] ?? $product->base_uom_id, '1', $amount);
            $priced = true;
        }
        if ($priced) {
            $data['prices'] = $prices;
        }
        if (! $isNew && count($data) === 1 && array_key_exists('track_stock', $data) && $data['track_stock'] === $product->track_stock) {
            return 'unchanged';
        }
        $this->catalog->saveProduct($actor, $product ?? new Product, $data, 'CSV import');

        return $isNew ? 'created' : 'updated';
    }

    public function exportRows(Actor $actor, array $options, ?Model $context): iterable
    {
        $lists = PriceList::where('is_active', true)->orderByDesc('is_default')->orderBy('code')->get();
        foreach (Product::with(['category', 'brand', 'taxGroup', 'baseUom', 'units.barcodes', 'prices'])->orderBy('sku')->lazy(500) as $p) {
            $base = $p->units->firstWhere('is_base', true);
            $row = [$p->sku, $p->name, $p->description, $p->category?->name, $p->brand?->name, $p->taxGroup?->code, $p->baseUom?->code,
                $p->track_stock ? 'yes' : 'no', $p->track_batches ? 'yes' : 'no', $p->is_active ? 'yes' : 'no', $p->plu, $base?->barcodes->pluck('code')->implode('|')];
            foreach ($lists as $list) {
                $price = $p->prices->first(fn ($x) => $x->price_list_id === $list->id && $x->product_uom_id === $base?->id && (float) $x->min_qty <= 1 && ! $x->starts_at && ! $x->ends_at);
                $row[] = $price ? Money::format($price->amount_minor, $list->currency_code) : '';
            }
            yield $row;
        }
    }

    /** A category or brand by name (created if allowed). */
    private function named(Actor $actor, string $class, string $name, bool $create, string $what): string
    {
        $name = trim($name);
        $found = $class::whereRaw('lower(name) = ?', [mb_strtolower($name)])->value('id');
        if ($found) {
            return $found;
        }
        if (! $create) {
            throw new DomainException("Unknown {$what} \"{$name}\".");
        }

        return $this->catalog->saveSimple($actor, new $class, ['name' => $name] + ($class === Category::class ? [] : []))->getKey();
    }
}
