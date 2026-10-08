<?php

namespace App\Domain\Catalog;

use App\Domain\Inventory\StockLedger;
use App\Domain\Pharmacy\PharmacyService;
use App\Domain\Services\PassService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Barcode;
use App\Models\Brand;
use App\Models\Category;
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
use Illuminate\Support\Carbon;

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

    public function saveProduct(Actor $actor, Product $product, array $data, ?string $reason = null): Product
    {
        $this->require($actor, 'catalog.manage');
        $pricesGiven = array_key_exists('prices', $data);
        if ($pricesGiven && ! $this->authz->allows($actor->user, 'pricing.manage')) {
            throw new AccessDenied('Changing prices needs the pricing permission.');
        }

        $product->fill(array_intersect_key($data, array_flip(['sku', 'name', 'description', 'category_id', 'brand_id', 'base_uom_id', 'tax_group_id', 'track_stock', 'is_active', 'track_batches', 'plu',
            'drug_schedule', 'generic_name', 'strength', 'dosage_form', 'fda_reg_no', 'station', 'track_serials', 'warranty_months', 'consignor_id', 'consignment_bp',
            'service_minutes', 'commission_bp', 'licence_keys'])));
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
        $product->sku = strtoupper(trim((string) $product->sku));
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
        if ($product->exists && $product->isDirty('base_uom_id') && $product->stockLevels()->exists()) {
            throw new DomainException('The base unit cannot change once stock has moved; create a new product instead.');
        }

        return $this->recorder->transaction(function () use ($actor, $product, $data, $pricesGiven, $reason, $startsBatches, $recipe) {
            $isNew = ! $product->exists;
            if ($startsBatches && ! $isNew) {
                app(StockLedger::class)->assignOpeningBatches($actor, $product); // stock already here becomes batch OPENING
            }
            $product->tenant_id = $actor->tenantId;
            $product->updated_by = $actor->id;
            if ($isNew) {
                $product->created_by = $actor->id;
            }
            $this->recorder->save($actor, $product, $isNew ? 'product.created' : 'product.updated', $reason);

            $summary = [];
            if (array_key_exists('units', $data) || $isNew) {
                $summary['units'] = $this->syncUnits($actor, $product, $data['units'] ?? [], $data['base_barcodes'] ?? []);
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

            return $product->fresh(['units.uom', 'barcodes', 'prices']);
        });
    }

    public function saveSimple(Actor $actor, Model $model, array $data, string $permission = 'catalog.manage'): Model
    {
        $this->require($actor, $permission);
        $model->fill($data);
        $model->tenant_id = $actor->tenantId;
        if ($model instanceof Uom) {
            $model->code = strtoupper(trim($model->code));
            if ($model->decimals < 0 || $model->decimals > 3) {
                throw new DomainException('A unit allows 0 to 3 decimal places.');
            }
        }
        $type = strtolower(class_basename($model));

        return $this->recorder->save($actor, $model, $type.($model->exists ? '.updated' : '.created'));
    }

    /** Exactly one default list; currency is the company's (multi-currency lists arrive with Finance). */
    public function savePriceList(Actor $actor, PriceList $list, array $data): PriceList
    {
        $this->require($actor, 'pricing.manage');
        $list->fill(array_intersect_key($data, array_flip(['code', 'name', 'prices_include_tax', 'is_default', 'pos_selectable', 'branch_id', 'is_active'])));
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

        $existing = $product->units()->get()->keyBy('uom_id');
        $changes = [];
        foreach ($wanted as $uomId => $w) {
            $pu = $existing[$uomId] ?? new ProductUom(['product_id' => $product->id, 'uom_id' => $uomId]);
            if ($pu->exists && Qty::factorMicro($pu->factor) !== Qty::factorMicro($w['factor']) && $product->stockLevels()->exists()) {
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
                $pu->forceFill(['is_sellable' => false, 'is_purchasable' => false])->save();
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
            if ($clash) {
                throw new DomainException("Barcode {$code} already belongs to {$clash->product->name}.");
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
        $units = $product->units()->get()->keyBy('uom_id');
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

        $existing = $product->prices()->get();
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
