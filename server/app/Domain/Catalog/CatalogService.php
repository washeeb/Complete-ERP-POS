<?php

namespace App\Domain\Catalog;

use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\ZeroStock;
use App\Domain\Pharmacy\PharmacyService;
use App\Domain\Services\PassService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Barcode;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Industry;
use App\Models\ModifierGroup;
use App\Models\Price;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\RecipeLine;
use App\Models\Supplier;
use App\Models\TaxGroup;
use App\Models\TaxRate;
use App\Models\Uom;
use App\Security\Authorizer;
use App\Support\Money;
use App\Support\Qty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Products with their selling units, barcodes and prices, saved together in one transaction.
 * Product fields need catalog.manage; any price change additionally needs pricing.manage.
 */
final class CatalogService
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz) {}

    /**
     * $data = product fields + optional:
     *   units:  [[uom_id, factor, is_sellable, is_purchasable, barcodes: [code…]], …] (base unit added automatically)
     *   base_barcodes: [code…]
     *   prices: [[price_list_id, uom_id, min_qty, amount (decimal string), starts_at, ends_at], …] — full replacement
     */
    /**
     * Restaurant recipe: what one base unit of the dish uses (deducted from stock when the dish is sold).
     *
     * @param  list<array{product_uom_id?: string, qty?: string}>  $rows
     * @return list<array{ingredient_id: string, ingredient_uom_id: string, qty: string}>
     */
    private function recipeLines(Product $product, array $rows): array
    {
        $out = [];
        foreach (array_values($rows) as $r) {
            if (empty($r['product_uom_id']) && trim((string) ($r['qty'] ?? '')) === '') {
                continue;
            }
            $unit = ProductUom::find($r['product_uom_id'] ?? null) ?? throw new DomainException('Recipe: choose the ingredient and its unit on every line.');
            $ingredient = Product::find($unit->product_id);
            $qty = Qty::toMilli((string) ($r['qty'] ?? '0'));
            if (! $ingredient || $ingredient->id === $product->id || ! $ingredient->track_stock) {
                throw new DomainException('Recipe: ingredients are other products that track stock.');
            }
            if ($qty <= 0) {
                throw new DomainException("Recipe: enter the quantity of {$ingredient->name}.");
            }
            if (isset($out[$ingredient->id])) {
                throw new DomainException("Recipe: {$ingredient->name} is listed twice.");
            }
            $out[$ingredient->id] = ['ingredient_id' => $ingredient->id, 'ingredient_uom_id' => $unit->id, 'qty' => Qty::format($qty)];
        }

        return array_values($out);
    }

    /**
     * A product's units (other than the base), base-unit barcodes and prices in the shape saveProduct() takes, so a caller
     * can change one part (e.g. one price) and send the rest back unchanged.
     *
     * @return array{units: list<array>, base_barcodes: list<string>, prices: list<array>}
     */
    public static function inputOf(Product $p): array
    {
        $p->load('units.uom', 'units.barcodes', 'prices.priceList', 'prices.productUom');
        $base = $p->units->firstWhere('is_base', true);

        return [
            'base_barcodes' => $base?->barcodes->pluck('code')->all() ?? [],
            'units' => $p->units->where('is_base', false)->where('is_sellable', true)->values()->map(fn ($u) => [
                'uom_id' => $u->uom_id, 'factor' => rtrim(rtrim((string) $u->factor, '0'), '.'), 'is_sellable' => $u->is_sellable,
                'is_purchasable' => $u->is_purchasable, 'barcodes' => $u->barcodes->pluck('code')->all(),
            ])->all(),
            'prices' => $p->prices->map(fn ($x) => [
                'price_list_id' => $x->price_list_id, 'uom_id' => $x->productUom->uom_id,
                'min_qty' => rtrim(rtrim((string) $x->min_qty, '0'), '.'), 'amount' => Money::format($x->amount_minor, $x->priceList->currency_code),
                'starts_at' => $x->starts_at?->toDateTimeString(), 'ends_at' => $x->ends_at?->toDateTimeString(),
            ])->values()->all(),
        ];
    }

    /** Price rows (as inputOf() gives them) with one row set: same list, unit, minimum quantity and dates are replaced. */
    public static function withPrice(array $rows, string $listId, string $uomId, string $minQty, string $amount, ?string $starts = null, ?string $ends = null): array
    {
        $key = fn ($r) => implode('|', [$r['price_list_id'], $r['uom_id'], Qty::toMilli((string) ($r['min_qty'] ?? '1')),
            empty($r['starts_at']) ? '' : Carbon::parse($r['starts_at'])->toDateTimeString(), empty($r['ends_at']) ? '' : Carbon::parse($r['ends_at'])->toDateTimeString()]);
        $new = ['price_list_id' => $listId, 'uom_id' => $uomId, 'min_qty' => $minQty, 'amount' => $amount, 'starts_at' => $starts, 'ends_at' => $ends];
        $rows = array_values(array_filter($rows, fn ($r) => $key($r) !== $key($new)));
        $rows[] = $new;

        return $rows;
    }

    /** Shared products (no industry) are numbered from here: six digits, no prefix, no leading zero for Excel to drop. */
    public const SHARED_SKU_FROM = 100000;

    /**
     * The next free SKU: an industry's products get its code and a six-digit running number (PHARMA-000123); shared
     * products a plain number (100123).
     */
    public function nextSku(string $tenantId, ?string $industryId): string
    {
        $prefix = substr($industryId ? (string) preg_replace('/[^A-Z0-9]/', '', strtoupper((string) DB::table('industries')->where('id', $industryId)->value('code'))) : '', 0, 10);
        $series = $prefix !== '' ? $prefix : 'SHARED';
        DB::table('document_sequences')->insertOrIgnore(['tenant_id' => $tenantId, 'node' => 'catalog', 'doc_type' => $series, 'last_value' => 0]);
        do {
            $n = DB::selectOne("UPDATE document_sequences SET last_value = last_value + 1 WHERE tenant_id = ? AND node = 'catalog' AND doc_type = ? RETURNING last_value",
                [$tenantId, $series])->last_value;
            $sku = $prefix !== '' ? sprintf('%s-%06d', $prefix, $n) : (string) (self::SHARED_SKU_FROM + $n);
        } while (Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('sku', $sku)->exists()); // skip codes typed in before

        return $sku;
    }

    /** The SKU was given by the system (any industry's series, a shared number, or the old SKU-000123), not typed in. */
    public static function systemSku(string $sku): bool
    {
        return (bool) preg_match('/^([A-Z0-9]{1,10}-\d{6}|1\d{5})$/', $sku);
    }

    /**
     * Move products to an industry's catalogue (null = shared by every industry). Stock and prices stay; a SKU the system
     * gave takes the new industry's series (PHARMA-000124), while SKUs typed in from an old system stay as they are.
     *
     * @param  iterable<Product>  $products
     * @return int how many moved
     */
    public function moveToIndustry(Actor $actor, iterable $products, ?string $industryId): int
    {
        $this->require($actor, 'catalog.manage');
        if ($industryId !== null && ! Industry::licensed()->whereKey($industryId)->exists()) {
            throw new DomainException('Choose an industry.');
        }
        $moved = 0;
        foreach ($products as $product) {
            if ($product->industry_id === $industryId) {
                continue;
            }
            $product->industry_id = $industryId;
            if (self::systemSku($product->sku)) {
                $product->sku = $this->nextSku($actor->tenantId, $industryId); // a system-given SKU follows the industry's series
            }
            $this->recorder->save($actor, $product, 'product.updated', 'Moved to another industry');
            $moved++;
        }

        return $moved;
    }

    public function saveProduct(Actor $actor, Product $product, array $data, ?string $reason = null): Product
    {
        $this->require($actor, 'catalog.manage');
        $pricesGiven = array_key_exists('prices', $data);
        if ($pricesGiven && ! $this->authz->allows($actor->user, 'pricing.manage')) {
            throw new AccessDenied('Changing prices needs the pricing permission.');
        }

        $product->fill(array_intersect_key($data, array_flip(['sku', 'name', 'description', 'category_id', 'brand_id', 'base_uom_id', 'tax_group_id', 'track_stock', 'is_active', 'track_batches', 'plu', 'location',
            'drug_schedule', 'generic_name', 'strength', 'dosage_form', 'fda_reg_no', 'station', 'track_serials', 'warranty_months', 'consignor_id', 'consignment_bp',
            'service_minutes', 'commission_bp', 'licence_keys',
            'is_container', 'deposit_product_id', 'deposit_per_unit', 'industry_id'])));
        $product->industry_id = $product->industry_id ?: null; // null = shared by every industry (products from before industries)
        // Distribution: returnable containers (crates, bottles, gas cylinders) and the container sold with each unit.
        $product->deposit_product_id = $product->deposit_product_id ?: null;
        if ($product->deposit_product_id) {
            $container = Product::find($product->deposit_product_id);
            if (! $container?->is_container || $container->id === $product->id || $product->is_container) {
                throw new DomainException('Deposit: choose a product marked as a returnable container (and not for a container itself).');
            }
            $per = (float) ($product->deposit_per_unit ?? 1);
            if ($per <= 0 || $per > 1000) {
                throw new DomainException('Containers per unit: more than 0 (e.g. 1 bottle, or 0.042 of a crate of 24).');
            }
            $product->deposit_per_unit = $product->deposit_per_unit ?? '1';
        } else {
            $product->deposit_per_unit = null;
        }
        // Services: a bookable service and how long it takes; a commission rate of its own; a prepaid pack; licence keys.
        $product->service_minutes = ($product->service_minutes === null || $product->service_minutes === '') ? null : (int) $product->service_minutes;
        $product->commission_bp = ($product->commission_bp === null || $product->commission_bp === '') ? null : (int) $product->commission_bp;
        if (($product->service_minutes !== null && ($product->service_minutes < 5 || $product->service_minutes > 720))
            || ($product->commission_bp !== null && ($product->commission_bp < 0 || $product->commission_bp > 10000))) {
            throw new DomainException('Services: a duration of 5 to 720 minutes, and a commission of 0 to 100 %.');
        }
        if (array_key_exists('pack', $data)) {
            $product->pack = PassService::normalisePack($data['pack']);
        }
        if (($product->pack || $product->licence_keys) && $product->track_stock) {
            throw new DomainException('A prepaid pack or a licence-key product does not hold stock: switch "Track stock" off.');
        }
        if ($product->pack && $product->service_minutes) {
            throw new DomainException('A prepaid pack is sold, not booked: leave its duration empty.');
        }
        // Fashion: consignment stock belongs to a supplier (the consignor), who is owed a share of each sale.
        $product->consignor_id = $product->consignor_id ?: null;
        $product->consignment_bp = $product->consignor_id ? (int) $product->consignment_bp : null;
        if ($product->consignor_id && (! Supplier::find($product->consignor_id) || $product->consignment_bp < 1 || $product->consignment_bp > 10000)) {
            throw new DomainException('Consignment: choose the consignor and their share (0.01–100 % of the price before tax).');
        }
        // Electronics: serial / IMEI numbers per unit, and the warranty from the date sold.
        if ($product->track_serials && (! $product->track_stock || $product->track_batches)) {
            throw new DomainException('Serial numbers need stock tracking, and cannot be combined with batches.');
        }
        $product->warranty_months = ($product->warranty_months === null || $product->warranty_months === '') ? null : (int) $product->warranty_months;
        if ($product->warranty_months !== null && ($product->warranty_months < 0 || $product->warranty_months > 120)) {
            throw new DomainException('Warranty: 0 to 120 months.');
        }
        // Restaurant: the kitchen station that prepares it, and the choices offered with it.
        $product->station = trim((string) $product->station) === '' ? null : strtoupper(trim((string) $product->station));
        if ($product->station !== null && ! preg_match('/^[A-Z0-9_-]{1,20}$/', $product->station)) {
            throw new DomainException('Kitchen station: a short code such as KITCHEN or BAR.');
        }
        if (array_key_exists('modifier_group_ids', $data)) {
            $ids = array_values(array_unique(array_filter((array) $data['modifier_group_ids'])));
            if (ModifierGroup::whereIn('id', $ids)->count() !== count($ids)) {
                throw new DomainException('Choose modifier groups from the list.');
            }
            $product->modifier_group_ids = $ids ?: null;
        }
        $recipe = array_key_exists('recipe', $data) ? $this->recipeLines($product, (array) $data['recipe']) : null;
        if (array_key_exists('active_ingredients', $data)) {
            $product->active_ingredients = PharmacyService::names($data['active_ingredients']);
        }
        $product->drug_schedule = $product->drug_schedule ?: null;
        if ($product->drug_schedule && ! array_key_exists($product->drug_schedule, Product::SCHEDULES)) {
            throw new DomainException('Choose the medicine schedule: over the counter, prescription only or controlled.');
        }
        if ($product->drug_schedule && ! $product->track_batches) {
            throw new DomainException('Medicines must track batches and expiry dates (Pharmacy).', 'batch_required');
        }
        $product->plu = $product->plu === null || trim((string) $product->plu) === '' ? null : trim((string) $product->plu);
        if ($product->plu !== null && (! preg_match('/^\d{1,5}$/', $product->plu) || Product::where('plu', $product->plu)->when($product->exists, fn ($q) => $q->whereKeyNot($product->id))->exists())) {
            throw new DomainException('Scale code (PLU): up to 5 digits, not used by another product.');
        }
        $startsBatches = $product->track_batches && (! $product->exists || $product->isDirty('track_batches'));
        // SKUs are given by the system (owner, 2026-10-13): the industry's code and a running number, never typed and never
        // changed, so two products can never share one. Fashion variants and tests may still pass their own.
        $product->sku = $product->exists ? $product->getOriginal('sku') : (strtoupper(trim((string) ($data['sku'] ?? ''))) ?: $this->nextSku($actor->tenantId, $product->industry_id));
        if (! preg_match('/^[A-Z0-9][A-Z0-9._-]{0,39}$/', $product->sku)) {
            throw new DomainException('SKU: letters, digits, dot, dash or underscore (max 40).');
        }
        if (Product::where('sku', $product->sku)->when($product->exists, fn ($q) => $q->whereKeyNot($product->id))->exists()) {
            throw new DomainException("SKU {$product->sku} is already used.");
        }
        $this->assertOwned($actor, Uom::class, $product->base_uom_id, 'base unit');
        $this->assertOwned($actor, TaxGroup::class, $product->tax_group_id, 'tax group');
        $this->assertOwned($actor, Category::class, $product->category_id, 'category', nullable: true);
        $this->assertOwned($actor, Brand::class, $product->brand_id, 'brand', nullable: true);
        if ($product->exists && $product->isDirty('base_uom_id') && $product->hasMoved()) {
            throw new DomainException('The base unit cannot change once stock has moved; use Correct the unit on the product (it renames the unit, quantities stay).');
        }

        return $this->recorder->transaction(function () use ($actor, $product, $data, $pricesGiven, $reason, $startsBatches, $recipe) {
            $isNew = ! $product->exists;
            if ($startsBatches && ! $isNew) {
                app(StockLedger::class)->assignOpeningBatches($actor, $product); // stock already here becomes batch OPENING
            }
            // A base unit changed before any stock (e.g. PC → PK): prices typed against the old unit follow it.
            $oldBaseUom = ! $isNew && $product->isDirty('base_uom_id') ? $product->getOriginal('base_uom_id') : null;
            if ($oldBaseUom && $pricesGiven) {
                $data['prices'] = array_map(fn ($r) => ($r['uom_id'] ?? null) === $oldBaseUom ? ['uom_id' => $product->base_uom_id] + $r : $r, $data['prices']);
            }
            $product->tenant_id = $actor->tenantId;
            $product->updated_by = $actor->id;
            if ($isNew) {
                $product->created_by = $actor->id;
            }
            $this->recorder->save($actor, $product, $isNew ? 'product.created' : 'product.updated', $reason);

            $summary = [];
            if (array_key_exists('units', $data) || $isNew || $oldBaseUom) {
                $keep = array_key_exists('units', $data) ? null : self::inputOf($product); // base unit changed from a file: keep the other units and barcodes
                $summary['units'] = $this->syncUnits($actor, $product, $data['units'] ?? $keep['units'] ?? [], $data['base_barcodes'] ?? $keep['base_barcodes'] ?? []);
            }
            if ($pricesGiven) {
                $summary['prices'] = $this->syncPrices($actor, $product, $data['prices']);
            }
            if ($recipe !== null) {
                $before = RecipeLine::where('product_id', $product->id)->get(['ingredient_id', 'ingredient_uom_id', 'qty'])
                    ->map(fn ($r) => [$r->ingredient_id, $r->ingredient_uom_id, Qty::format(Qty::toMilli((string) $r->qty))])->all();
                $after = array_map(fn ($r) => [$r['ingredient_id'], $r['ingredient_uom_id'], $r['qty']], $recipe);
                if ($before !== $after) {
                    RecipeLine::where('product_id', $product->id)->delete();
                    foreach ($recipe as $r) {
                        RecipeLine::create(['product_id' => $product->id] + $r);
                    }
                    $summary['recipe'] = count($recipe).' ingredient(s)';
                }
            }
            if (array_filter($summary)) {
                $this->recorder->audit->record($actor, 'product.details_changed', $product,
                    array_map(fn ($v) => [null, $v], array_filter($summary)), $reason);
                $this->recorder->outbox->publish($actor, $product->fresh(), 'product.details_changed',
                    ['product_id' => $product->id] + array_filter($summary));
            }

            ZeroStock::ensure($actor->tenantId, [$product->id]); // on Stock on hand from the start, in each store of its industry

            return $product->fresh(['units.uom', 'barcodes', 'prices']);
        });
    }

    public function saveSimple(Actor $actor, Model $model, array $data, string $permission = 'catalog.manage'): Model
    {
        $this->require($actor, $permission);
        $model->fill($data);
        $model->tenant_id = $actor->tenantId;
        // One of each: the same category (under the same parent), brand or unit code twice is refused, whoever adds it.
        $other = fn ($q) => $q->when($model->exists, fn ($w) => $w->whereKeyNot($model->getKey()));
        if ($model instanceof Category || $model instanceof Brand) {
            $model->name = trim((string) $model->name);
            $same = $model::query()->whereRaw('lower(name) = ?', [mb_strtolower($model->name)])->tap($other)
                ->when($model instanceof Category, fn ($q) => $model->parent_id ? $q->where('parent_id', $model->parent_id) : $q->whereNull('parent_id'))->exists();
            if ($same) {
                throw new DomainException(($model instanceof Category ? 'Category' : 'Brand')." {$model->name} already exists.");
            }
        }
        if ($model instanceof Uom) {
            // The code can be corrected: products, tills and new documents follow; documents already made keep what they showed.
            $model->code = strtoupper(trim($model->code));
            if (! preg_match('/^[A-Z0-9]{1,10}$/', $model->code)) {
                throw new DomainException('Unit code: up to 10 letters or digits, e.g. TAB, STRIP, PK.');
            }
            if (Uom::where('code', $model->code)->tap($other)->exists()) {
                throw new DomainException("Unit {$model->code} already exists.");
            }
            if ($model->decimals < 0 || $model->decimals > 3) {
                throw new DomainException('A unit allows 0 to 3 decimal places.');
            }
        }
        $type = strtolower(class_basename($model));

        return $this->recorder->save($actor, $model, $type.($model->exists ? '.updated' : '.created'));
    }

    /** What products can be moved between: field => [model, label]. */
    public const MOVABLE = ['category_id' => [Category::class, 'category'], 'brand_id' => [Brand::class, 'brand'], 'tax_group_id' => [TaxGroup::class, 'tax group']];

    /**
     * Move products to another category, brand or tax group (or none, for category and brand). Each product is saved
     * like an edit (audited, synced); nothing else about them changes.
     *
     * @param  iterable<Product|string>  $products
     */
    public function moveProducts(Actor $actor, iterable $products, string $field, ?string $to, ?string $reason = null): int
    {
        $this->require($actor, 'catalog.manage');
        [$class, $what] = self::MOVABLE[$field] ?? throw new DomainException('Products can be moved between categories, brands or tax groups.');
        if ($to === null && $field === 'tax_group_id') {
            throw new DomainException('Every product needs a tax group.');
        }
        if ($to !== null) {
            $this->assertOwned($actor, $class, $to, $what);
        }
        // Only this one field changes (audited, synced). Not a full re-save: a product made before a newer rule (e.g. a
        // medicine without batches) would refuse it and stop the whole move, and nothing it checks is touched here.
        $ids = [];
        foreach ($products as $p) {
            $ids[] = $p instanceof Product ? $p->id : $p; // ids first: moving shrinks the list a lazy query is paging through
        }
        $n = 0;
        foreach (array_chunk($ids, 200) as $chunk) {
            foreach (Product::whereIn('id', $chunk)->get() as $p) { // as it is now, not as the list loaded it
                if ($p->{$field} === $to) {
                    continue;
                }
                $p->{$field} = $to;
                $p->updated_by = $actor->id;
                $this->recorder->save($actor, $p, 'product.updated', $reason ?? "Moved to another {$what}");
                $n++;
            }
        }

        return $n;
    }

    /**
     * Merge a duplicate unit into the right one (PCS into PC): every product's unit row and base unit move over, with
     * their factors, prices and barcodes. The duplicate stays (the stock history refers to it) but is no longer offered,
     * and is deleted when nothing refers to it. Refused for products that have both units (one must be removed first).
     */
    public function mergeUom(Actor $actor, Uom $from, Uom $to, ?string $reason = null): int
    {
        $this->require($actor, 'catalog.manage');
        if ($from->id === $to->id || $to->merged_into_id) {
            throw new DomainException('Choose another unit that is in use.');
        }
        if ($from->merged_into_id) {
            throw new DomainException("{$from->code} is already merged.");
        }
        $both = ProductUom::with('product')->where('uom_id', $from->id)->whereIn('product_id', ProductUom::where('uom_id', $to->id)->select('product_id'))->get();
        if ($both->isNotEmpty()) {
            throw new DomainException("These products have both {$from->code} and {$to->code}: remove one of the two on the product first: "
                .$both->take(5)->map(fn ($u) => "{$u->product->sku} {$u->product->name}")->implode(', ').($both->count() > 5 ? '…' : ''));
        }
        $why = $reason ?: "Unit {$from->code} merged into {$to->code}";

        return $this->recorder->transaction(function () use ($actor, $from, $to, $why) {
            $products = ProductUom::where('uom_id', $from->id)->pluck('product_id')->merge(Product::where('base_uom_id', $from->id)->pluck('id'))->unique();
            ProductUom::where('uom_id', $from->id)->update(['uom_id' => $to->id, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            foreach (Product::whereIn('id', $products)->get() as $p) {
                if ($p->base_uom_id === $from->id) {
                    $p->base_uom_id = $to->id;
                    $this->recorder->save($actor, $p, 'product.updated', $why);
                } else {
                    $this->recorder->audit->record($actor, 'product.details_changed', $p, ['units' => [$from->code, $to->code]], $why);
                }
                $this->recorder->outbox->publish($actor, $p->fresh(), 'product.details_changed', ['product_id' => $p->id, 'units' => $why]); // units travel with the product
            }
            $from->merged_into_id = $to->id;
            $this->recorder->save($actor, $from, 'uom.merged', $why);

            return $products->count();
        });
    }

    /**
     * Delete a category, brand or unit that nothing uses (no products, sub-categories, styles, promotions or units).
     * Anything in use is refused with what still uses it; move those first. Audited, and branch servers follow.
     */
    public function deleteSimple(Actor $actor, Model $model, string $permission = 'catalog.manage'): void
    {
        $this->require($actor, $permission);
        $label = $model instanceof Uom ? "unit {$model->code}" : strtolower(class_basename($model))." {$model->name}";
        $uses = match (true) {
            $model instanceof Category => array_filter([
                'products' => Product::where('category_id', $model->id)->count(),
                'sub-categories' => Category::where('parent_id', $model->id)->count(),
                'styles' => DB::table('styles')->where('category_id', $model->id)->count(),
                'promotions' => DB::table('promotions')->whereRaw('category_ids @> ?::jsonb', [json_encode([$model->id])])->count(),
            ]),
            $model instanceof Brand => array_filter(['products' => Product::where('brand_id', $model->id)->count(),
                'styles' => DB::table('styles')->where('brand_id', $model->id)->count()]),
            $model instanceof Uom => array_filter(['products' => ProductUom::where('uom_id', $model->id)->distinct()->count('product_id')]),
            default => throw new DomainException('Only categories, brands and units can be deleted here.'),
        };
        if ($uses) {
            throw new DomainException(ucfirst($label).' is still used by '.collect($uses)->map(fn ($n, $k) => "{$n} {$k}")->implode(', ')
                .'. Move them to another one first.');
        }
        $this->recorder->transaction(function () use ($actor, $model, $label) {
            $this->recorder->outbox->publish($actor, $model, strtolower(class_basename($model)).'.deleted'); // branch servers remove it too
            $this->recorder->audit->record($actor, strtolower(class_basename($model)).'.deleted', $model, ['name' => [$model->name, null]], "Deleted {$label}");
            try {
                $model->delete();
            } catch (QueryException $e) {
                throw new DomainException(ucfirst($label).' is still used elsewhere (e.g. past documents) and cannot be deleted.');
            }
        });
    }

    /** Exactly one default list; currency is the company's (multi-currency lists arrive with Finance). */
    public function savePriceList(Actor $actor, PriceList $list, array $data): PriceList
    {
        $this->require($actor, 'pricing.manage');
        $list->fill(array_intersect_key($data, array_flip(['code', 'name', 'prices_include_tax', 'is_default', 'pos_selectable', 'branch_id', 'is_active', 'industry_id'])));
        $list->industry_id = $list->industry_id ?: null;
        $list->code = strtoupper(trim($list->code));
        $list->tenant_id = $actor->tenantId;
        $list->currency_code ??= $actor->user->tenant->currency_code;
        if ($list->exists && $list->getOriginal('is_default') && ! $list->is_default) {
            throw new DomainException('Choose another list as the default instead of switching this one off.');
        }
        if ($list->is_default && ! $list->is_active) {
            throw new DomainException('The default price list must stay active.');
        }
        if ($list->isDirty('prices_include_tax') && $list->exists && $list->prices()->exists()) {
            throw new DomainException('Prices on this list were entered '.($list->getOriginal('prices_include_tax') ? 'including' : 'excluding')
                .' tax. Create a new list rather than reinterpreting them.');
        }

        return $this->recorder->transaction(function () use ($actor, $list) {
            if ($list->is_default) {
                foreach (PriceList::where('is_default', true)->whereKeyNot($list->id ?? '00000000-0000-0000-0000-000000000000')->get() as $other) {
                    $other->is_default = false;
                    $this->recorder->save($actor, $other, 'price_list.updated', 'Another list became the default');
                }
            }

            return $this->recorder->save($actor, $list, $list->exists ? 'price_list.updated' : 'price_list.created');
        });
    }

    /** @param list<string> $rateIds */
    public function saveTaxGroup(Actor $actor, TaxGroup $group, array $data, array $rateIds): TaxGroup
    {
        $this->require($actor, 'pricing.manage');
        $group->fill(array_intersect_key($data, array_flip(['code', 'name'])));
        $group->code = strtoupper(trim($group->code));
        $group->tenant_id = $actor->tenantId;

        return $this->recorder->transaction(function () use ($actor, $group, $rateIds) {
            $before = $group->exists ? $group->rates()->pluck('tax_rates.id')->sort()->values()->all() : [];
            $after = collect($rateIds)->sort()->values()->all();
            $ratesChanged = $before !== $after;
            if ($ratesChanged && $group->verified_at) {
                // A changed tax set-up needs a fresh sign-off.
                $group->verified_at = null;
                $group->verified_by = null;
                $group->verified_by_name = null;
            }
            $this->recorder->save($actor, $group, $group->exists ? 'tax_group.updated' : 'tax_group.created');
            if ($ratesChanged) {
                $group->rates()->sync($rateIds);
                $this->recorder->outbox->publish($actor, $group->fresh(), 'tax_group.rates_changed');
                $this->recorder->audit->record($actor, 'tax_group.rates_changed', $group, ['rates' => [
                    TaxRate::whereIn('id', $before)->pluck('code')->all(), TaxRate::whereIn('id', $after)->pluck('code')->all(),
                ]]);
            }

            return $group;
        });
    }

    /** The accountant (or owner on their advice) confirms the tax set-up; recorded by name. */
    public function verifyTaxGroup(Actor $actor, TaxGroup $group, string $note): TaxGroup
    {
        $this->require($actor, 'pricing.manage');
        if (trim($note) === '') {
            throw new DomainException('Say who checked it and on what basis (e.g. “Checked with GRA guidance by our accountant, A. Mensah”).');
        }
        $group->verified_at = now();
        $group->verified_by = $actor->id;
        $group->verified_by_name = $actor->name;

        return $this->recorder->save($actor, $group, 'tax_group.verified', $note);
    }

    /**
     * Correct a product's base unit when it was set wrongly (e.g. Pieces instead of Pack), even after stock has moved.
     * Only the name of the unit changes: 40 pieces become 40 packs. Prices, barcodes, stock and history stay with the same
     * unit row; documents already printed keep the unit they showed. Refused when the product already has the new unit
     * as a pack size, or when stock is in fractions the new unit cannot hold.
     */
    public function correctBaseUnit(Actor $actor, Product $product, string $uomId, string $reason): Product
    {
        $this->require($actor, 'catalog.manage');
        $this->assertOwned($actor, Uom::class, $uomId, 'unit');
        if (trim($reason) === '') {
            throw new DomainException('Give the reason for the correction.');
        }
        if ($uomId === $product->base_uom_id) {
            throw new DomainException('That is already the base unit.');
        }
        $new = Uom::findOrFail($uomId);
        if ($product->units()->where('uom_id', $uomId)->exists()) {
            throw new DomainException("{$product->name} already has {$new->code} as another unit: remove it first (Barcodes and selling units), then correct the base unit.");
        }
        $step = 10 ** (3 - min(3, $new->decimals));
        foreach ($product->stockLevels()->get(['on_hand']) as $l) {
            if (Qty::toMilli((string) $l->on_hand) % $step !== 0) {
                throw new DomainException("Stock of {$product->name} is in fractions that {$new->code} does not allow ({$new->decimals} decimal places).");
            }
        }
        $old = Uom::find($product->base_uom_id);

        return $this->recorder->transaction(function () use ($actor, $product, $new, $old, $reason) {
            $base = $product->units()->where('is_base', true)->firstOrFail();
            $base->uom_id = $new->id;
            $this->recorder->save($actor, $base, 'product_uom.base_unit_corrected', $reason);
            $product->base_uom_id = $new->id;
            $this->recorder->save($actor, $product, 'product.base_unit_corrected', "{$old?->code} → {$new->code}: ".trim($reason), payload: ['from' => $old?->code, 'to' => $new->code]);

            return $product->fresh();
        });
    }

    // -------------------------------------------------------------------

    private function syncUnits(Actor $actor, Product $product, array $units, array $baseBarcodes): array
    {
        $wanted = [$product->base_uom_id => ['factor' => '1', 'is_base' => true, 'is_sellable' => true, 'is_purchasable' => true, 'barcodes' => $baseBarcodes]];
        foreach ($units as $u) {
            if (empty($u['uom_id']) || $u['uom_id'] === $product->base_uom_id) {
                continue;
            }
            $this->assertOwned($actor, Uom::class, $u['uom_id'], 'unit');
            $factor = (string) $u['factor'];
            if (! preg_match('/^\d{1,9}(\.\d{1,6})?$/', $factor) || (float) $factor <= 0) {
                throw new DomainException('Each unit needs a positive conversion factor, e.g. 1 carton = 24.');
            }
            $wanted[$u['uom_id']] = ['factor' => $factor, 'is_base' => false, 'is_sellable' => (bool) ($u['is_sellable'] ?? true),
                'is_purchasable' => (bool) ($u['is_purchasable'] ?? true), 'barcodes' => $u['barcodes'] ?? []];
        }

        // The base unit was changed (no stock yet): the old base row takes the new unit, so its prices and barcodes follow.
        $oldBase = $product->units()->where('is_base', true)->first();
        if ($oldBase && $oldBase->uom_id !== $product->base_uom_id && ! $product->units()->where('uom_id', $product->base_uom_id)->exists()) {
            $oldBase->forceFill(['uom_id' => $product->base_uom_id])->save();
        }
        $existing = $product->units()->get()->keyBy('uom_id');
        $changes = [];
        foreach ($wanted as $uomId => $w) {
            $pu = $existing[$uomId] ?? new ProductUom(['product_id' => $product->id, 'uom_id' => $uomId]);
            if ($pu->exists && Qty::factorMicro($pu->factor) !== Qty::factorMicro($w['factor']) && $product->hasMoved()) {
                throw new DomainException('A unit’s conversion factor cannot change once stock has moved; add a new unit instead.');
            }
            $pu->fill(['factor' => $w['factor'], 'is_base' => $w['is_base'], 'is_sellable' => $w['is_sellable'], 'is_purchasable' => $w['is_purchasable']]);
            $pu->tenant_id = $actor->tenantId;
            if ($pu->isDirty()) {
                $pu->save();
                $changes[] = Uom::find($uomId)->code.' × '.rtrim(rtrim($w['factor'], '0'), '.');
            }
            $this->syncBarcodes($actor, $product, $pu, $w['barcodes'], $changes);
        }
        foreach ($existing as $uomId => $pu) {
            if (! isset($wanted[$uomId])) {
                // Units with history stay (receipts reference them); they just stop being sellable.
                $pu->forceFill(['is_base' => false, 'is_sellable' => false, 'is_purchasable' => false])->save();
                $pu->barcodes()->delete();
                $changes[] = 'retired '.Uom::find($uomId)->code;
            }
        }

        return $changes;
    }

    private function syncBarcodes(Actor $actor, Product $product, ProductUom $pu, array $codes, array &$changes): void
    {
        $codes = array_values(array_unique(array_filter(array_map(fn ($c) => trim((string) $c), $codes))));
        foreach ($codes as $code) {
            if (! preg_match('/^[A-Za-z0-9\-.]{3,64}$/', $code)) {
                throw new DomainException("Barcode “{$code}” has invalid characters.");
            }
            $clash = Barcode::where('code', $code)->where('product_uom_id', '!=', $pu->id)->with('product')->first();
            if ($clash && $clash->product_id !== $product->id) {
                throw new DomainException("Barcode {$code} already belongs to {$clash->product->name} ({$clash->product->sku}).");
            }
            if ($clash) { // on another unit of this same product (e.g. its base unit was changed): the barcode moves here
                $clash->delete();
                $changes[] = "barcode {$code} moved";
            }
        }
        $current = $pu->barcodes()->pluck('code')->all();
        foreach (array_diff($codes, $current) as $code) {
            $b = new Barcode(['product_id' => $product->id, 'product_uom_id' => $pu->id, 'code' => $code]);
            $b->tenant_id = $actor->tenantId;
            $b->save();
            $changes[] = "+barcode {$code}";
        }
        $removed = array_diff($current, $codes);
        if ($removed) {
            $pu->barcodes()->whereIn('code', $removed)->delete();
            $changes[] = '−barcode '.implode(', ', $removed);
        }
    }

    private function syncPrices(Actor $actor, Product $product, array $rows): array
    {
        $units = $product->units()->with('uom')->get()->keyBy('uom_id');
        $wanted = [];
        foreach ($rows as $r) {
            $list = PriceList::find($r['price_list_id'] ?? null) ?? throw new DomainException('Choose a price list for each price.');
            $pu = $units[$r['uom_id'] ?? ''] ?? throw new DomainException('A price is for a unit this product does not have.');
            $minQty = Qty::toMilli((string) ($r['min_qty'] ?? '1'));
            if ($minQty <= 0) {
                throw new DomainException('Minimum quantity must be above zero.');
            }
            $amount = Money::toMinor((string) $r['amount'], $list->currency_code);
            if ($amount < 0) {
                throw new DomainException('Prices cannot be negative.');
            }
            $starts = ! empty($r['starts_at']) ? Carbon::parse($r['starts_at']) : null;
            $ends = ! empty($r['ends_at']) ? Carbon::parse($r['ends_at']) : null;
            if ($starts && $ends && $ends->lte($starts)) {
                throw new DomainException('A price must end after it starts.');
            }
            $key = implode('|', [$list->id, $pu->id, $minQty, $starts?->toIso8601String(), $ends?->toIso8601String()]);
            if (isset($wanted[$key])) {
                throw new DomainException('The same price row is listed twice.');
            }
            $wanted[$key] = compact('list', 'pu', 'minQty', 'amount', 'starts', 'ends');
        }

        $existing = $product->prices()->with('priceList')->get();
        $changes = [];
        $keep = [];
        foreach ($wanted as $key => $w) {
            $match = $existing->first(fn (Price $p) => implode('|', [$p->price_list_id, $p->product_uom_id, Qty::toMilli($p->min_qty),
                $p->starts_at?->toIso8601String(), $p->ends_at?->toIso8601String()]) === $key);
            $price = $match ?? new Price;
            $old = $match?->amount_minor;
            $price->fill([
                'price_list_id' => $w['list']->id, 'product_id' => $product->id, 'product_uom_id' => $w['pu']->id,
                'min_qty' => Qty::format($w['minQty']), 'amount_minor' => $w['amount'], 'starts_at' => $w['starts'], 'ends_at' => $w['ends'],
            ]);
            $price->tenant_id = $actor->tenantId;
            $price->updated_by = $actor->id;
            if ($price->isDirty()) {
                if ($price->exists) {
                    $price->version++;
                }
                $price->save();
                $changes[] = "{$w['list']->code} {$w['pu']->uom->code}".($w['minQty'] !== 1000 ? ' ≥'.Qty::format($w['minQty']) : '')
                    .': '.($old === null ? '' : Money::format($old, $w['list']->currency_code).' → ').Money::format($w['amount'], $w['list']->currency_code);
            }
            $keep[] = $price->id;
        }
        foreach ($existing as $p) {
            if (! in_array($p->id, $keep, true)) {
                $changes[] = "removed {$p->priceList->code} price ".Money::format($p->amount_minor, $p->priceList->currency_code);
                $p->delete();
            }
        }

        return $changes;
    }

    private function assertOwned(Actor $actor, string $class, ?string $id, string $what, bool $nullable = false): void
    {
        if ($id === null || $id === '') {
            if ($nullable) {
                return;
            }
            throw new DomainException("Choose a {$what}.");
        }
        if (! $class::withoutGlobalScopes()->where('tenant_id', $actor->tenantId)->whereKey($id)->exists()) {
            throw new DomainException("Unknown {$what}.");
        }
    }

    private function require(Actor $actor, string $permission): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, $permission)) {
            throw new AccessDenied;
        }
    }
}
