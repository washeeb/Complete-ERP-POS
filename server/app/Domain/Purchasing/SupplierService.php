<?php

namespace App\Domain\Purchasing;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\ProductUom;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\Tenant;
use App\Security\Authorizer;
use App\Support\Caps;
use App\Support\Money;
use App\Support\Qty;

final class SupplierService
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly DocumentNumbers $numbers) {}

    /**
     * @param array{code?: string, name?: string, products?: list<array{product_uom_id: string, supplier_sku?: ?string,
     *   last_cost?: ?string, min_order_qty?: ?string, is_preferred?: bool}>} $data products, when given, replace the list
     */
    public function save(Actor $actor, Supplier $supplier, array $data): Supplier
    {
        // Suppliers serve the whole company: a buyer granted for one branch may add and edit them too.
        if (! $actor->user || ! $this->authz->allowsAnywhere($actor->user, 'suppliers.manage')) {
            throw new AccessDenied;
        }
        $data = Caps::keys($data, ['name', 'contact_name']);
        // The code is given by the system when left empty (SUP-HQ-000001), like customers; a typed one is kept.
        $code = strtoupper(trim((string) ($data['code'] ?? $supplier->code))) ?: $this->numbers->next($actor->tenantId, 'supplier');
        if (! preg_match('/^[A-Z0-9][A-Z0-9\-]{0,19}$/', $code)) {
            throw new DomainException('Supplier codes use letters, digits and dashes (up to 20).');
        }
        if (Supplier::where('code', $code)->when($supplier->exists, fn ($q) => $q->whereKeyNot($supplier->id))->exists()) {
            throw new DomainException("Supplier code {$code} is already used.");
        }
        if (trim((string) ($data['name'] ?? $supplier->name)) === '') {
            throw new DomainException('Enter the supplier name.');
        }
        $currency = Tenant::find($actor->tenantId)->currency_code;

        return $this->recorder->transaction(function () use ($actor, $supplier, $data, $code, $currency) {
            $supplier->fill(array_intersect_key($data, array_flip(['name', 'contact_name', 'phone', 'email', 'tax_no', 'address',
                'payment_terms_days', 'lead_time_days', 'return_days_before_expiry', 'is_active', 'notes'])));
            $supplier->code = $code;
            $supplier->currency_code ??= $currency;
            if (! $supplier->exists) {
                $supplier->tenant_id = $actor->tenantId;
            }
            $this->recorder->save($actor, $supplier, $supplier->exists ? 'supplier.updated' : 'supplier.created');
            if (array_key_exists('products', $data) && $this->syncProducts($supplier, $data['products'] ?? [], $currency)) {
                $this->recorder->audit->record($actor, 'supplier.products_changed', $supplier, ['products' => [null, count($data['products'] ?? [])]]);
                $this->recorder->outbox->publish($actor, $supplier->fresh(), 'supplier.products_changed');
            }

            return $supplier->fresh('products');
        });
    }

    private function syncProducts(Supplier $supplier, array $rows, string $currency): bool
    {
        $keep = [];
        foreach ($rows as $r) {
            $unit = ProductUom::find($r['product_uom_id'] ?? null) ?? throw new DomainException('Choose the product and the unit you buy it in.');
            if (in_array($unit->id, $keep, true)) {
                throw new DomainException('Each product unit can be listed once per supplier.');
            }
            $cost = isset($r['last_cost']) && $r['last_cost'] !== '' ? Money::toMinor((string) $r['last_cost'], $currency) : null;
            if ($cost !== null && $cost < 0) {
                throw new DomainException('Costs cannot be negative.');
            }
            SupplierProduct::updateOrCreate(['supplier_id' => $supplier->id, 'product_uom_id' => $unit->id], [
                'product_id' => $unit->product_id, 'supplier_sku' => $r['supplier_sku'] ?? null, 'last_cost_minor' => $cost,
                'min_order_qty' => Qty::format(Qty::toMilli((string) ($r['min_order_qty'] ?? '0') ?: '0')), 'is_preferred' => (bool) ($r['is_preferred'] ?? false),
            ]);
            $keep[] = $unit->id;
        }
        $removed = SupplierProduct::where('supplier_id', $supplier->id)->whereNotIn('product_uom_id', $keep)->delete();

        return $keep !== [] || $removed > 0;
    }

    /** The preferred supplier line for a product (else the cheapest known), used to fill purchase orders. */
    public static function bestFor(string $productId): ?SupplierProduct
    {
        return SupplierProduct::where('product_id', $productId)->whereHas('supplier', fn ($q) => $q->where('is_active', true))
            ->orderByDesc('is_preferred')->orderByRaw('last_cost_minor IS NULL')->orderBy('last_cost_minor')->first();
    }
}
