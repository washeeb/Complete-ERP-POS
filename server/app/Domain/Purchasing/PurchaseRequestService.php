<?php

namespace App\Domain\Purchasing;

use App\Domain\Messaging\Notifier;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Account;
use App\Models\Department;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Money;
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

    /**
     * @param array{warehouse_id: string, needed_by?: ?string, note?: ?string, department_id?: ?string, lines: list<array{product_uom_id?: ?string, account_id?: ?string,
     *   description?: ?string, uom_code?: ?string, est_unit_cost?: ?string, qty: string|int|float, note?: ?string, department_id?: ?string}>} $data
     *   a line is a product, or a service / non-stock item (account + description, with an estimated price for the budget)
     */
    public function request(Actor $actor, array $data): PurchaseRequest
    {
        $warehouse = Warehouse::findOrFail($data['warehouse_id'] ?? null);
        $this->require($actor, 'purchasing.request', $warehouse);
        $lines = [];
        $currency = Tenant::find($actor->tenantId)->currency_code;
        $department = ($data['department_id'] ?? null) ? Department::findOrFail($data['department_id'])->id : null;
        foreach (array_values($data['lines'] ?? []) as $i => $r) {
            $qty = Qty::toMilli((string) ($r['qty'] ?? '0'));
            $est = ($r['est_unit_cost'] ?? null) !== null && $r['est_unit_cost'] !== '' ? Money::toMinor((string) $r['est_unit_cost'], $currency) : null;
            if (empty($r['product_uom_id'])) {
                $account = Account::find($r['account_id'] ?? null) ?? throw new DomainException('Choose a product, or what kind of spending a service or non-stock line is.');
                if ($account->is_header || $account->control || ! $account->is_active || ! in_array($account->type, ['expense', 'asset'], true)) {
                    throw new DomainException("{$account->label()} cannot take a request line: choose an expense (or asset) account.");
                }
                $name = trim((string) ($r['description'] ?? ''));
                if ($name === '') {
                    throw new DomainException('Describe each service or non-stock line (e.g. "Service the air conditioners").');
                }
                $item = ['product_id' => null, 'product_uom_id' => null, 'sku' => null, 'name' => mb_substr($name, 0, 160), 'account_id' => $account->id,
                    'uom_code' => mb_substr(trim((string) ($r['uom_code'] ?? '')) ?: 'EA', 0, 10)];
            } else {
                $unit = ProductUom::with('uom')->find($r['product_uom_id']) ?? throw new DomainException('Choose a product and unit on every line.');
                $product = Product::findOrFail($unit->product_id);
                $item = ['product_id' => $product->id, 'product_uom_id' => $unit->id, 'sku' => $product->sku, 'name' => $product->name, 'account_id' => null, 'uom_code' => $unit->uom->code];
            }
            if ($qty <= 0) {
                throw new DomainException("{$item['name']}: enter a quantity above zero.");
            }
            if ($est !== null && $est < 0) {
                throw new DomainException("{$item['name']}: the estimated price cannot be negative.");
            }
            $lines[] = ['line_no' => $i + 1] + $item + ['qty' => Qty::format($qty), 'note' => ($r['note'] ?? null) ? mb_substr(trim($r['note']), 0, 255) : null,
                'est_unit_cost_minor' => $est, 'department_id' => (($r['department_id'] ?? null) ? Department::findOrFail($r['department_id'])->id : null) ?? $department];
        }
        if ($lines === []) {
            throw new DomainException('Add at least one line.');
        }
        $neededBy = ($data['needed_by'] ?? null) ? Carbon::parse($data['needed_by'])->toDateString() : null;

        return $this->recorder->transaction(function () use ($actor, $data, $warehouse, $lines, $neededBy, $department) {
            $r = (new PurchaseRequest)->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'purchase_request'),
                'warehouse_id' => $warehouse->id, 'branch_id' => $warehouse->branch_id, 'status' => 'requested', 'needed_by' => $neededBy,
                'note' => ($data['note'] ?? null) ?: null, 'requested_by' => $actor->user?->id, 'requested_by_name' => $actor->name, 'department_id' => $department]);
            $this->recorder->save($actor, $r, 'purchase_request.created', branchId: $warehouse->branch_id);
            foreach ($lines as $l) {
                PurchaseRequestLine::create(['purchase_request_id' => $r->id] + $l);
            }
            $this->recorder->outbox->publish($actor, $r->fresh(), 'purchase_request.lines_changed'); // the lines travel inside the snapshot
            $this->notifier->toPermission($actor->tenantId, 'purchasing.manage', $warehouse->branch_id, "Purchase request {$r->number} from {$actor->name}",
                count($lines)." product(s) for {$warehouse->name}".($neededBy ? ", needed by {$neededBy}" : '').'. Purchasing → Purchase requests.',
                url('/admin/purchase-requests'), [$actor->user?->id], topic: 'purchasing');

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
            if (! $l->product_uom_id) { // a service or non-stock item: the requester's estimate until the supplier's price is known
                $guessed = true;

                return ['account_id' => $l->account_id, 'description' => $l->name, 'uom_code' => $l->uom_code, 'qty' => (string) $l->qty,
                    'unit_cost' => number_format(((int) $l->est_unit_cost_minor) / 100, 2, '.', ''), 'department_id' => $l->department_id];
            }
            $cost = SupplierProduct::where('supplier_id', $supplier->id)->where('product_uom_id', $l->product_uom_id)->value('last_cost_minor');
            if ($cost === null) {
                $guessed = true;
                $avg = DB::table('stock_levels')->where('warehouse_id', $r->warehouse_id)->where('product_id', $l->product_id)->value('avg_cost_minor');
                $factor = (float) (ProductUom::find($l->product_uom_id)?->factor ?? 1);
                $cost = (int) round(((float) ($avg ?? 0)) * $factor);
            }

            return ['product_uom_id' => $l->product_uom_id, 'qty' => (string) $l->qty, 'unit_cost' => number_format($cost / 100, 2, '.', ''), 'department_id' => $l->department_id];
        })->all();

        return $this->recorder->transaction(function () use ($actor, $r, $supplier, $lines, $guessed) {
            $po = $this->orders->saveDraft($actor, new PurchaseOrder, ['supplier_id' => $supplier->id, 'warehouse_id' => $r->warehouse_id, 'department_id' => $r->department_id,
                'expected_on' => $r->needed_by?->toDateString(),
                'note' => "From purchase request {$r->number} ({$r->requested_by_name})".($guessed ? '. Check the prices: some had no previous price from this supplier.' : ''),
                'lines' => $lines]);
            $r->forceFill(['status' => 'ordered', 'purchase_order_id' => $po->id, 'closed_at' => now(), 'closed_by_name' => $actor->name]);
            $this->recorder->save($actor, $r, 'purchase_request.ordered', "Purchase order {$po->number}", $r->branch_id);

            return $po;
        });
    }

    /** The budget for the request's service and non-stock lines at their estimated prices (empty when the check is off or there are none). */
    public function budget(PurchaseRequest $r): array
    {
        $check = app(BudgetCheck::class);

        return $check->mode($r->tenant_id) === 'off' ? [] : $check->check($r->tenant_id, $r->branch_id, $r->lines->map(fn ($l) => ['account_id' => $l->account_id,
            'net_minor' => Qty::divRound(Qty::toMilli((string) $l->qty) * (int) $l->est_unit_cost_minor, 1000)])->all());
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
