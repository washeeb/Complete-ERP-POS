<?php

namespace App\Domain\Purchasing;

use App\Domain\Pricing\TaxCalculator;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Money;
use App\Support\Qty;

/**
 * Purchase orders: draft → submitted for approval → approved (by someone else, within their limit) → received
 * through goods received notes. Costs are before tax; tax is worked out from each product's tax group.
 */
final class PurchaseOrderService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
    ) {}

    /**
     * @param array{supplier_id?: string, warehouse_id?: string, expected_on?: ?string, note?: ?string,
     *   lines?: list<array{product_uom_id: string, qty: string, unit_cost?: ?string}>} $data
     */
    public function saveDraft(Actor $actor, PurchaseOrder $po, array $data): PurchaseOrder
    {
        $warehouse = Warehouse::findOrFail($data['warehouse_id'] ?? $po->warehouse_id);
        $this->require($actor, 'purchasing.manage', $warehouse);
        if ($po->exists && $po->status !== 'draft') {
            throw new DomainException('Only a draft purchase order can be changed. Ask the approver to reject it back to draft.');
        }
        $supplier = Supplier::findOrFail($data['supplier_id'] ?? $po->supplier_id);
        if (! $supplier->is_active) {
            throw new DomainException("{$supplier->name} is inactive.");
        }
        $currency = Tenant::find($actor->tenantId)->currency_code;
        $lines = array_key_exists('lines', $data) ? $this->priceLines($supplier, $data['lines'] ?? [], $currency) : null;

        return $this->recorder->transaction(function () use ($actor, $po, $data, $warehouse, $supplier, $currency, $lines) {
            $isNew = ! $po->exists;
            $po->fill(['supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'branch_id' => $warehouse->branch_id]
                + array_intersect_key($data, array_flip(['expected_on', 'note'])));
            if ($isNew) {
                $po->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'purchase_order'),
                    'status' => 'draft', 'currency_code' => $currency, 'created_by' => $actor->id, 'created_by_name' => $actor->name]);
            }
            if ($lines !== null) {
                $po->forceFill(['net_minor' => array_sum(array_column($lines, 'net_minor')), 'tax_minor' => array_sum(array_column($lines, 'tax_minor')),
                    'total_minor' => array_sum(array_column($lines, 'total_minor'))]);
            }
            $this->recorder->save($actor, $po, $isNew ? 'purchase_order.created' : 'purchase_order.updated');
            if ($lines !== null) {
                PurchaseOrderLine::where('purchase_order_id', $po->id)->delete();
                foreach ($lines as $l) {
                    PurchaseOrderLine::create(['purchase_order_id' => $po->id] + $l);
                }
                $this->recorder->audit->record($actor, 'purchase_order.lines_changed', $po, ['lines' => [null, count($lines)], 'total_minor' => [null, $po->total_minor]]);
                $this->recorder->outbox->publish($actor, $po->fresh(), 'purchase_order.lines_changed');
            }

            return $po->fresh('lines');
        });
    }

    /**
     * Order again: a new draft with the same supplier, store and lines. Costs are the supplier's latest known cost
     * (goods received update it), else the old order's; inactive products are left out.
     */
    public function copy(Actor $actor, PurchaseOrder $from): PurchaseOrder
    {
        $currency = $from->currency_code;
        $lines = $from->lines()->get()->filter(fn ($l) => Product::where('id', $l->product_id)->where('is_active', true)->exists())
            ->map(fn ($l) => ['product_uom_id' => $l->product_uom_id, 'qty' => (string) $l->qty,
                'unit_cost' => SupplierProduct::where('supplier_id', $from->supplier_id)->where('product_uom_id', $l->product_uom_id)->whereNotNull('last_cost_minor')->exists()
                    ? null : Money::format((int) $l->unit_cost_minor, $currency)])
            ->values()->all();
        if ($lines === []) {
            throw new DomainException("None of the products on {$from->number} can be ordered any more.");
        }

        return $this->saveDraft($actor, new PurchaseOrder, ['supplier_id' => $from->supplier_id, 'warehouse_id' => $from->warehouse_id,
            'note' => "Ordered again from {$from->number}", 'lines' => $lines]);
    }

    public function submit(Actor $actor, PurchaseOrder $po): PurchaseOrder
    {
        $this->require($actor, 'purchasing.manage', $po->warehouse);
        $this->expect($po, ['draft']);
        if ($po->lines()->count() === 0) {
            throw new DomainException('Add at least one line before submitting.');
        }
        $po->forceFill(['status' => 'pending_approval', 'submitted_at' => now(), 'rejected_reason' => null]);

        return $this->recorder->save($actor, $po, 'purchase_order.submitted');
    }

    /** Creator never approves their own order (segregation of duties); the total must be within the approver's limit. */
    public function approve(Actor $actor, PurchaseOrder $po): PurchaseOrder
    {
        $this->require($actor, 'purchasing.approve', $po->warehouse);
        $this->expect($po, ['pending_approval']);
        if ($po->created_by && $po->created_by === $actor->id) {
            throw new DomainException('You created this purchase order, so someone else must approve it.', 'own_document');
        }
        if (! $this->authz->allowsAmount($actor->user, 'purchasing.approve', $po->warehouse, 'po_approve_minor', $po->total_minor)) {
            $limit = $this->authz->limit($actor->user, 'purchasing.approve', $po->warehouse, 'po_approve_minor') ?? 0;
            throw new DomainException('This order ('.Money::display($po->total_minor, $po->currency_code).') is above your approval limit ('
                .Money::display($limit, $po->currency_code).'). Someone with a higher limit must approve it.', 'over_limit');
        }
        $po->forceFill(['status' => 'approved', 'approved_by' => $actor->id, 'approved_by_name' => $actor->name, 'approved_at' => now()]);

        return $this->recorder->save($actor, $po, 'purchase_order.approved');
    }

    public function reject(Actor $actor, PurchaseOrder $po, string $reason): PurchaseOrder
    {
        $this->require($actor, 'purchasing.approve', $po->warehouse);
        $this->expect($po, ['pending_approval']);
        $po->forceFill(['status' => 'draft', 'rejected_reason' => $this->reason($reason), 'submitted_at' => null]);

        return $this->recorder->save($actor, $po, 'purchase_order.rejected', $reason);
    }

    public function cancel(Actor $actor, PurchaseOrder $po, string $reason): PurchaseOrder
    {
        $this->require($actor, 'purchasing.manage', $po->warehouse);
        $this->expect($po, ['draft', 'pending_approval', 'approved']);
        if ($po->receipts()->where('status', '<>', 'reversed')->exists()) {
            throw new DomainException('Goods were already received on this order; close it short instead.');
        }
        $po->forceFill(['status' => 'cancelled', 'close_reason' => $this->reason($reason), 'closed_at' => now()]);

        return $this->recorder->save($actor, $po, 'purchase_order.cancelled', $reason);
    }

    /** Nothing more is expected from the supplier: the rest of the order lapses. */
    public function closeShort(Actor $actor, PurchaseOrder $po, string $reason): PurchaseOrder
    {
        $this->require($actor, 'purchasing.manage', $po->warehouse);
        $this->expect($po, ['approved', 'partially_received']);
        $po->forceFill(['status' => 'closed', 'close_reason' => $this->reason($reason), 'closed_at' => now()]);

        return $this->recorder->save($actor, $po, 'purchase_order.closed', $reason);
    }

    /** Recomputes the receiving status from the lines (called by goods received). */
    public static function statusFromLines(PurchaseOrder $po): string
    {
        $lines = $po->lines()->get();
        $any = $lines->contains(fn ($l) => Qty::toMilli((string) $l->received_qty) > 0);
        $all = $lines->every(fn ($l) => Qty::toMilli((string) $l->received_qty) >= Qty::toMilli((string) $l->qty));

        return $all ? 'received' : ($any ? 'partially_received' : 'approved');
    }

    /** @return list<array<string, mixed>> */
    private function priceLines(Supplier $supplier, array $rows, string $currency): array
    {
        $out = [];
        foreach (array_values($rows) as $i => $r) {
            $unit = ProductUom::with('uom')->find($r['product_uom_id'] ?? null) ?? throw new DomainException('Choose a product and unit on every line.');
            $product = Product::with('taxGroup.rates')->findOrFail($unit->product_id);
            $qty = Qty::toMilli((string) ($r['qty'] ?? '0'));
            if ($qty <= 0) {
                throw new DomainException("{$product->name}: enter a quantity above zero.");
            }
            $cost = isset($r['unit_cost']) && $r['unit_cost'] !== '' && $r['unit_cost'] !== null
                ? Money::toMinor((string) $r['unit_cost'], $currency)
                : SupplierProduct::where('supplier_id', $supplier->id)->where('product_uom_id', $unit->id)->value('last_cost_minor');
            if ($cost === null) {
                throw new DomainException("{$product->name}: enter the cost (no previous cost from {$supplier->name}).");
            }
            if ($cost < 0) {
                throw new DomainException('Costs cannot be negative.');
            }
            $net = Qty::divRound($qty * $cost, 1000);
            $rates = $product->taxGroup?->rates->where('is_active', true)->map(fn ($x) => ['code' => $x->code, 'name' => $x->name, 'rate_bp' => $x->rate_bp])->values()->all() ?? [];
            $t = TaxCalculator::line($net, $rates, false);
            $out[] = ['line_no' => $i + 1, 'product_id' => $product->id, 'product_uom_id' => $unit->id, 'sku' => $product->sku, 'name' => $product->name,
                'uom_code' => $unit->uom->code, 'factor' => $unit->factor, 'qty' => Qty::format($qty), 'received_qty' => '0.000', 'unit_cost_minor' => $cost,
                'net_minor' => $t['net'], 'tax_minor' => $t['tax'], 'total_minor' => $t['gross'], 'tax_breakdown' => $t['breakdown']];
        }
        if ($out === []) {
            throw new DomainException('Add at least one line.');
        }

        return $out;
    }

    private function expect(PurchaseOrder $po, array $statuses): void
    {
        if (! in_array($po->status, $statuses, true)) {
            throw new DomainException("Purchase order {$po->number} is ".(PurchaseOrder::STATUSES[$po->status] ?? $po->status).'; that is not possible now.', 'wrong_status');
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Give a reason.');
        }

        return mb_substr($reason, 0, 255);
    }

    private function require(Actor $actor, string $permission, $target): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, $permission, $target)) {
            throw new AccessDenied;
        }
    }
}
