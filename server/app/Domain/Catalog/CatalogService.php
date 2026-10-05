<?php

namespace App\Domain\Catalog;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Barcode;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Price;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\TaxGroup;
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
    public function saveProduct(Actor $actor, Product $product, array $data, ?string $reason = null): Product
    {
        $this->require($actor, 'catalog.manage');
        $pricesGiven = array_key_exists('prices', $data);
        if ($pricesGiven && ! $this->authz->allows($actor->user, 'pricing.manage')) {
            throw new AccessDenied('Changing prices needs the pricing permission.');
        }

        $product->fill(array_intersect_key($data, array_flip(['sku', 'name', 'description', 'category_id', 'brand_id', 'base_uom_id', 'tax_group_id', 'track_stock', 'is_active'])));
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

        return $this->recorder->transaction(function () use ($actor, $product, $data, $pricesGiven, $reason) {
            $isNew = ! $product->exists;
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
