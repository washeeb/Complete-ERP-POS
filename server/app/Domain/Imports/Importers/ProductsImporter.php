<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Catalog\CatalogService;
use App\Domain\Imports\Importer;
use App\Domain\Purchasing\ReorderLevels;
use App\Domain\Purchasing\ReorderService;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Industry;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ReorderRule;
use App\Models\TaxGroup;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Money;
use App\Support\Qty;
use Illuminate\Database\Eloquent\Model;

/**
 * Products — create new: every row is a new product. A row whose SKU exists, or whose name already exists in the same
 * industry, is refused (it would be a duplicate): changes to existing products go through Products — update existing
 * (ProductsUpdateImporter), which never creates. Prices go in columns named price_<list code> (price_RETAIL): the
 * base-unit price from 1 piece, without dates.
 */
class ProductsImporter extends Importer
{
    private ?bool $hasIndustries = null;

    public function __construct(private readonly CatalogService $catalog) {}

    public function key(): string
    {
        return 'products';
    }

    /** create: new products only; update: existing products only (by SKU). */
    protected function mode(): string
    {
        return 'create';
    }

    public function label(): string
    {
        return 'Products — create new';
    }

    public function description(): string
    {
        return 'Adds NEW products, one per row. A row whose SKU already exists, or whose name already exists in the same industry, is refused so nothing is '
            .'created twice: to change existing products use "Products — update existing". Leave sku empty and the system gives the SKU. '
            .'Category and brand are matched by name (and created if "Create missing categories and brands" is on). Barcodes: several separated by |. '
            .'Prices: a column per price list, named price_ followed by the list code (e.g. price_RETAIL); they set the price of one base unit. '
            .'Changing prices needs the pricing permission.';
    }

    public function industryScoped(): bool
    {
        return $this->mode() === 'create';
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
            ['name' => 'base_unit', 'required' => false, 'help' => 'Unit stock is counted in: its code or name (PC, KG, TAB or Tablet). New products: PC if empty. After stock has moved, a different unit only corrects the name (40 pieces become 40 packs).', 'example' => 'PC'],
            ['name' => 'track_stock', 'required' => false, 'help' => 'yes / no. New products: yes if empty.', 'example' => 'yes'],
            ['name' => 'track_batches', 'required' => false, 'help' => 'yes / no — batches and expiry dates.', 'example' => 'no'],
            ['name' => 'for_sale', 'required' => false, 'help' => 'yes / no — no takes it off the tills and shop.', 'example' => 'yes'],
            ['name' => 'plu', 'required' => false, 'help' => 'Scale code for weighed items (up to 5 digits).', 'example' => ''],
            ['name' => 'location', 'required' => false, 'help' => 'Where it is kept, e.g. Shelf B2 (shows on stock sheets and the till).', 'example' => ''],
            ['name' => 'industry', 'required' => false, 'help' => 'Leave empty: new products go to the industry chosen on the import page. Or an industry name or code (e.g. Pharmacy), or "shared" for every industry. Existing products move only when this column is filled.', 'example' => ''],
            ['name' => 'barcodes', 'required' => false, 'help' => 'Barcodes of one base unit, separated by |. Replaces the current base-unit barcodes. In Excel, format this column as Text first, or Excel drops leading zeros and shortens long numbers.', 'example' => '6001067000123'],
            ['name' => 'min_qty', 'required' => false, 'help' => 'Own minimum stock, in the unit the product is bought in (e.g. packs), for every store. Empty: unchanged (new products follow the company default in Settings → Stock). "default" goes back to the company default.', 'example' => ''],
            ['name' => 'max_qty', 'required' => false, 'help' => 'Order up to this many (buying unit). Empty with a min_qty: twice the minimum.', 'example' => ''],
            ['name' => 'drug_schedule', 'required' => false, 'help' => 'Medicines: otc (over the counter), pom (prescription only) or controlled. pom and controlled need a pharmacist\'s approval at the till; controlled drugs go in the controlled-drug register. A medicine tracks batches and expiry (switched on for you). "none" = not a medicine. Empty: unchanged.', 'example' => ''],
            ['name' => 'generic_name', 'required' => false, 'help' => 'Medicines: generic (INN) name, e.g. Paracetamol.', 'example' => ''],
            ['name' => 'strength', 'required' => false, 'help' => 'Medicines: e.g. 500 mg.', 'example' => ''],
            ['name' => 'dosage_form', 'required' => false, 'help' => 'Medicines: tablet, capsule, syrup, injection…', 'example' => ''],
            ['name' => 'fda_reg_no', 'required' => false, 'help' => 'Medicines: FDA registration number.', 'example' => ''],
            ['name' => 'active_ingredients', 'required' => false, 'help' => 'Medicines: active ingredients separated by | (allergy and interaction warnings), e.g. Amoxicillin|Clavulanic acid.', 'example' => ''],
            ['name' => 'dosage', 'required' => false, 'help' => 'Usual directions, printed on the dosage label at the till, e.g. 1 tablet 3 times daily after meals for 5 days.', 'example' => ''],
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
        return ['create_missing' => true, 'allow_same_name' => false];
    }

    /** The SKU of a product with this name (spaces and case ignored) in the same industry, or among shared products. */
    private static function sameName(string $name, ?string $industryId): ?string
    {
        $norm = mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $name)));

        return Product::whereRaw("lower(regexp_replace(trim(name), '\\s+', ' ', 'g')) = ?", [$norm])
            ->where(fn ($q) => $industryId === null ? $q->whereNull('industry_id') : $q->where('industry_id', $industryId))->value('sku');
    }

    public function apply(Actor $actor, array $row, array $options, ?Model $context): string
    {
        $sku = self::has($row, 'sku') ? strtoupper(trim((string) $row['sku'])) : '';
        $product = $sku !== '' ? Product::where('sku', $sku)->first() : null;
        if ($this->mode() === 'update' && ! $product) {
            throw new DomainException($sku === '' ? 'Give the SKU of the product to update (download the current products first).'
                : "No product with SKU {$sku}. To add new products, use \"Products — create new\".");
        }
        if ($this->mode() === 'create' && $product) {
            throw new DomainException("SKU {$sku} already exists ({$product->name}). To change it, use \"Products — update existing\".");
        }
        $isNew = ! $product;
        // Empty: the system gives the new product its SKU. A code brought from the previous system is kept, so that its
        // prices and opening stock (imported next, by that code) find it; codes stay unique either way.
        $data = $isNew && $sku !== '' ? ['sku' => $sku] : [];
        if ($isNew) {
            $data['name'] = self::need($row, 'name', 'the name (new product)');
        } elseif (self::has($row, 'name')) {
            $data['name'] = $row['name'];
        }
        if (self::has($row, 'industry')) {
            $data['industry_id'] = self::industryId($row['industry']);
        } elseif ($isNew) {
            // Not given (e.g. a company without industries, or a call from code): shared, unless industries exist.
            $this->hasIndustries ??= Industry::licensed()->exists();
            $chosen = (string) ($options['industry'] ?? ($this->hasIndustries ? '' : 'shared'));
            $data['industry_id'] = $chosen === 'shared' ? null : ($chosen !== '' ? $chosen : throw new DomainException('Choose which industry these products are for (on the import page).'));
        }
        if ($isNew && empty($options['allow_same_name']) && ($same = self::sameName($data['name'], $data['industry_id'] ?? null))) {
            throw new DomainException("A product named \"{$data['name']}\" already exists (SKU {$same}). To change it, use \"Products — update existing\"; "
                .'to add a different product with the same name, tick "Allow names that already exist".');
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
            // A product whose stock has moved: the unit is corrected (renamed, quantities stay), not converted.
            if (! $isNew && $data['base_uom_id'] !== $product->base_uom_id && $product->hasMoved()) {
                $product = $this->catalog->correctBaseUnit($actor, $product, $data['base_uom_id'], 'Products file');
            }
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
        if (self::has($row, 'location')) {
            $data['location'] = mb_substr(trim((string) $row['location']), 0, 60);
        }
        if (self::has($row, 'drug_schedule')) {
            $data['drug_schedule'] = self::schedule((string) $row['drug_schedule']);
            if ($data['drug_schedule'] && ! ($data['track_batches'] ?? $product?->track_batches)) {
                $data['track_batches'] = true; // every medicine is received and sold by batch and expiry
            }
        }
        foreach (['generic_name' => 255, 'strength' => 60, 'dosage_form' => 40, 'fda_reg_no' => 60, 'dosage' => 255] as $col => $max) {
            if (self::has($row, $col)) {
                $data[$col] = mb_substr(trim((string) $row[$col]), 0, $max);
            }
        }
        if (self::has($row, 'active_ingredients')) {
            $data['active_ingredients'] = self::list($row['active_ingredients']);
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
        $levels = self::has($row, 'min_qty') || self::has($row, 'max_qty');
        if (! $levels && ! $isNew && count($data) === 1 && array_key_exists('track_stock', $data) && $data['track_stock'] === $product->track_stock) {
            return 'unchanged';
        }
        $saved = $this->catalog->saveProduct($actor, $product ?? new Product, $data, 'CSV import');
        if ($levels) {
            $this->levels($actor, $saved, $row);
        }

        return $isNew ? 'created' : 'updated';
    }

    public function exportRows(Actor $actor, array $options, ?Model $context): iterable
    {
        $lists = PriceList::where('is_active', true)->orderByDesc('is_default')->orderBy('code')->get();
        $chosen = (string) ($options['industry'] ?? '');
        $products = Product::with(['category', 'brand', 'taxGroup', 'baseUom', 'units.barcodes', 'prices', 'industry'])
            ->when($chosen === 'shared', fn ($q) => $q->whereNull('industry_id'))
            ->when($chosen !== '' && $chosen !== 'shared', fn ($q) => $q->where('industry_id', $chosen));
        foreach ($products->orderBy('sku')->lazy(500) as $p) {
            $base = $p->units->firstWhere('is_base', true);
            $row = [$p->sku, $p->name, $p->description, $p->category?->name, $p->brand?->name, $p->taxGroup?->code, $p->baseUom?->code,
                $p->track_stock ? 'yes' : 'no', $p->track_batches ? 'yes' : 'no', $p->is_active ? 'yes' : 'no', $p->plu, $p->location, $p->industry?->name ?? 'shared', $base?->barcodes->pluck('code')->implode('|'), ...self::ownLevels($p),
                $p->drug_schedule, $p->generic_name, $p->strength, $p->dosage_form, $p->fda_reg_no, implode('|', $p->active_ingredients ?? []), $p->dosage];
            foreach ($lists as $list) {
                $price = $p->prices->first(fn ($x) => $x->price_list_id === $list->id && $x->product_uom_id === $base?->id && (float) $x->min_qty <= 1 && ! $x->starts_at && ! $x->ends_at);
                $row[] = $price ? Money::format($price->amount_minor, $list->currency_code) : '';
            }
            yield $row;
        }
    }

    /** otc / pom / controlled from the code or the words people type; none (or "no") clears it. */
    private static function schedule(string $value): ?string
    {
        $v = strtolower(trim($value));

        return match (true) {
            in_array($v, ['none', 'no', '-', 'not a medicine'], true) => null,
            in_array($v, ['otc', 'over the counter', 'over-the-counter', 'gsl', 'general sale'], true) => 'otc',
            in_array($v, ['pom', 'prescription', 'prescription only', 'prescription-only', 'rx', 'p'], true) => 'pom',
            in_array($v, ['controlled', 'controlled drug', 'cd', 'narcotic'], true) => 'controlled',
            default => throw new DomainException('drug_schedule: otc, pom or controlled (or none).'),
        };
    }

    /** Own minimum / maximum (buying unit) in every store the person manages, or back to the company default. */
    private function levels(Actor $actor, Product $product, array $row): void
    {
        $warehouses = Warehouse::where('is_active', true)->orderBy('code')->get()
            ->filter(fn ($w) => $actor->user && app(Authorizer::class)->allowsAny($actor->user, ['warehouse.manage', 'stock.levels'], $w));
        if ($warehouses->isEmpty()) {
            throw new DomainException('Setting minimum stock needs the permission to set reorder levels.');
        }
        $reorder = app(ReorderService::class);
        if (strtolower(trim((string) ($row['min_qty'] ?? ''))) === 'default') {
            foreach (ReorderRule::where('product_id', $product->id)->whereIn('warehouse_id', $warehouses->pluck('id'))->where('source', '<>', 'default')->get() as $rule) {
                $reorder->useDefault($actor, $rule);
            }

            return;
        }
        $factor = ReorderLevels::buyingUnit($product->id)['factor'];
        $min = Qty::toMilli(self::number((string) ($row['min_qty'] ?? ''), 'min_qty'));
        $max = self::has($row, 'max_qty') ? Qty::toMilli(self::number((string) $row['max_qty'], 'max_qty')) : 2 * $min;
        $base = fn (int $milli) => Qty::format((int) round($milli * $factor / 1_000_000));
        foreach ($warehouses as $w) {
            $reorder->saveRule($actor, $w, $product, $base($min), $base($max));
        }
    }

    /** A product's own minimum and maximum (buying unit) when every store has the same, for the download. */
    private static function ownLevels(Product $product): array
    {
        $rules = ReorderRule::where('product_id', $product->id)->where('source', '<>', 'default')->get(['min_qty', 'max_qty']);
        if ($rules->isEmpty() || $rules->unique(fn ($r) => $r->min_qty.'|'.$r->max_qty)->count() > 1) {
            return ['', ''];
        }
        $factor = ReorderLevels::buyingUnit($product->id)['factor'];
        $unit = fn ($v) => Qty::format((int) round(Qty::toMilli((string) $v) * 1_000_000 / $factor));

        return [$unit($rules[0]->min_qty), $unit($rules[0]->max_qty)];
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

    /** An industry by name or code; "shared" (or "all") for products every industry sells. */
    private static function industryId(string $value): ?string
    {
        $v = mb_strtolower(trim($value));
        if (in_array($v, ['shared', 'all', 'all industries'], true)) {
            return null;
        }

        return Industry::licensed()->where(fn ($q) => $q->whereRaw('lower(name) = ?', [$v])->orWhereRaw('lower(code) = ?', [$v])->orWhere('module_key', $v))->value('id')
            ?? (mb_strlen($v) >= 3 ? Industry::licensed()->whereRaw('lower(name) like ?', [$v.'%'])->orderBy('sort')->value('id') : null)
            ?? throw new DomainException("Unknown industry \"{$value}\". Use its name (Company → Industries) or \"shared\".");
    }
}
