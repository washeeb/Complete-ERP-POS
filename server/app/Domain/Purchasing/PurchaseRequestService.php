<?php

namespace App\Domain\Purchasing;

use App\Domain\Messaging\Notifier;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Qty;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Purchase requests (owner request 2026-10-20): the stock controller lists what a store needs and the buyers are told;
 * a buyer turns it into a draft purchase order with a supplier (prices from the supplier list, as for any order), or
 * rejects it with the reason; the person who asked can cancel it while it waits. Who asks does not order or pay.
 */
final class PurchaseRequestService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly PurchaseOrderService $orders,
        private readonly Notifier $notifier,
    ) {}

    /** @param array{warehouse_id: string, needed_by?: ?string, note?: ?string, lines: list<array{product_uom_id: string, qty: string|int|float, note?: ?string}>} $data */
    public function request(Actor $actor, array $data): PurchaseRequest
    {
        $warehouse = Warehouse::findOrFail($data['warehouse_id'] ?? null);
        $this->require($actor, 'purchasing.request', $warehouse);
        $lines = [];
        foreach (array_values($data['lines'] ?? []) as $i => $r) {
            $unit = ProductUom::with('uom')->find($r['product_uom_id'] ?? null) ?? throw new DomainException('Choose a product and unit on every line.');
            $product = Product::findOrFail($unit->product_id);
            $qty = Qty::toMilli((string) ($r['qty'] ?? '0'));
            if ($qty <= 0) {
                throw new DomainException("{$product->name}: enter a quantity above zero.");
            }
            $lines[] = ['line_no' => $i + 1, 'product_id' => $product->id, 'product_uom_id' => $unit->id, 'sku' => $product->sku, 'name' => $product->name,
                'uom_code' => $unit->uom->code, 'qty' => Qty::format($qty), 'note' => ($r['note'] ?? null) ? mb_substr(trim($r['note']), 0, 255) : null];
        }
        if ($lines === []) {
            throw new DomainException('Add at least one line.');
        }
        $neededBy = ($data['needed_by'] ?? null) ? Carbon::parse($data['needed_by'])->toDateString() : null;

        return $this->recorder->transaction(function () use ($actor, $data, $warehouse, $lines, $neededBy) {
            $r = (new PurchaseRequest)->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'purchase_request'),
                'warehouse_id' => $warehouse->id, 'branch_id' => $warehouse->branch_id, 'status' => 'requested', 'needed_by' => $neededBy,
                'note' => ($data['note'] ?? null) ?: null, 'requested_by' => $actor->user?->id, 'requested_by_name' => $actor->name]);
            $this->recorder->save($actor, $r, 'purchase_request.created', branchId: $warehouse->branch_id);
            foreach ($lines as $l) {
                PurchaseRequestLine::create(['purchase_request_id' => $r->id] + $l);
            }
            $this->recorder->outbox->publish($actor, $r->fresh(), 'purchase_request.lines_changed'); // the lines travel inside the snapshot
            $this->notifier->toPermission($actor->tenantId, 'purchasing.manage', $warehouse->branch_id, "Purchase request {$r->number} from {$actor->name}",
                count($lines)." product(s) for {$warehouse->name}".($neededBy ? ", needed by {$neededBy}" : '').'. Purchasing → Purchase requests.',
                url('/admin/purchase-requests'), [$actor->user?->id]);

            return $r->fresh('lines');
        });
    }

    /** A buyer orders it: a draft purchase order to the supplier for the store, with the requested lines (still editable). */
    public function order(Actor $actor, PurchaseRequest $r, Supplier $supplier): PurchaseOrder
    {
        $this->require($actor, 'purchasing.manage', $r->warehouse);
        if ($r->status !== 'requested') {
            throw new DomainException("{$r->number} is ".strtolower(PurchaseRequest::STATUSES[$r->status] ?? $r->status).'.');
        }

        // Each line's cost: the supplier's last price for that unit, else the store's average cost, else 0 for the buyer to fill in.
        $guessed = false;
        $lines = $r->lines->map(function ($l) use ($supplier, $r, &$guessed) {
            $cost = SupplierProduct::where('supplier_id', $supplier->id)->where('product_uom_id', $l->product_uom_id)->value('last_cost_minor');
            if ($cost === null) {
                $guessed = true;
                $avg = DB::table('stock_levels')->where('warehouse_id', $r->warehouse_id)->where('product_id', $l->product_id)->value('avg_cost_minor');
                $factor = (float) (ProductUom::find($l->product_uom_id)?->factor ?? 1);
                $cost = (int) round(((float) ($avg ?? 0)) * $factor);
            }

            return ['product_uom_id' => $l->product_uom_id, 'qty' => (string) $l->qty, 'unit_cost' => number_format($cost / 100, 2, '.', '')];
        })->all();

        return $this->recorder->transaction(function () use ($actor, $r, $supplier, $lines, $guessed) {
            $po = $this->orders->saveDraft($actor, new PurchaseOrder, ['supplier_id' => $supplier->id, 'warehouse_id' => $r->warehouse_id,
                'expected_on' => $r->needed_by?->toDateString(),
                'note' => "From purchase request {$r->number} ({$r->requested_by_name})".($guessed ? '. Check the prices: some had no previous price from this supplier.' : ''),
                'lines' => $lines]);
            $r->forceFill(['status' => 'ordered', 'purchase_order_id' => $po->id, 'closed_at' => now(), 'closed_by_name' => $actor->name]);
            $this->recorder->save($actor, $r, 'purchase_request.ordered', "Purchase order {$po->number}", $r->branch_id);

            return $po;
        });
    }

    public function reject(Actor $actor, PurchaseRequest $r, string $reason): PurchaseRequest
    {
        $this->require($actor, 'purchasing.manage', $r->warehouse);

        return $this->close($actor, $r, 'rejected', $reason);
    }

    /** The person who asked (or a buyer) withdraws it while it waits. */
    public function cancel(Actor $actor, PurchaseRequest $r, string $reason): PurchaseRequest
    {
        if ($r->requested_by !== $actor->id) {
            $this->require($actor, 'purchasing.manage', $r->warehouse);
        }

        return $this->close($actor, $r, 'cancelled', $reason);
    }

    private function close(Actor $actor, PurchaseRequest $r, string $status, string $reason): PurchaseRequest
    {
        if (trim($reason) === '') {
            throw new DomainException('Give the reason.');
        }
        if ($r->status !== 'requested') {
            throw new DomainException("{$r->number} is no longer waiting.");
        }
        $r->forceFill(['status' => $status, 'closed_at' => now(), 'closed_by_name' => $actor->name, 'closed_reason' => mb_substr(trim($reason), 0, 255)]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $r, "purchase_request.{$status}", $reason, $r->branch_id));
    }

    public function can(Actor $actor, string $permission, Warehouse $w): bool
    {
        return $actor->user !== null && $this->authz->allows($actor->user, $permission, $w);
    }

    private function require(Actor $actor, string $permission, Warehouse $w): void
    {
        if (! $this->can($actor, $permission, $w)) {
            throw new AccessDenied;
        }
    }
}
