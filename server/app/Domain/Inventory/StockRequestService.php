<?php

namespace App\Domain\Inventory;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\StockRequest;
use App\Models\StockRequestLine;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Qty;
use Illuminate\Support\Facades\DB;

/**
 * A branch asks another for stock (owner decisions 2026-10-12, industries part B).
 *
 * - Sister branches of one industry (or head office): the supplier sends it, or says no.
 * - Between two industries: a manager of the asking branch (not the person who asked) approves, then a manager of the
 *   supplying branch; only then is it sent. The goods move at the sender's average cost, and for each item asked for
 *   the supplier names the product of its own catalogue it sends (one base unit for one base unit).
 *
 * Sending creates and dispatches an ordinary transfer; receiving that transfer closes the request.
 */
final class StockRequestService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly TransferService $transfers,
    ) {}

    /** @param array{from_warehouse_id: string, to_warehouse_id: string, note?: ?string, lines: list<array{product_uom_id: string, qty: string}>} $data */
    public function request(Actor $actor, array $data): StockRequest
    {
        $to = Warehouse::with('branch')->findOrFail($data['to_warehouse_id'] ?? null);
        $from = Warehouse::with('branch')->findOrFail($data['from_warehouse_id'] ?? null);
        $this->require($actor, 'stock.request', $to);
        if ($from->id === $to->id) {
            throw new DomainException('Ask a different warehouse from your own.');
        }
        if (! $from->is_active) {
            throw new DomainException("{$from->name} is not in use.");
        }
        $toIndustry = $to->branch->industry_id;
        $lines = [];
        foreach (array_values($data['lines'] ?? []) as $i => $r) {
            $unit = ProductUom::with('uom')->find($r['product_uom_id'] ?? null) ?? throw new DomainException('Choose a product and unit on every line.');
            $product = Product::findOrFail($unit->product_id);
            $qty = Qty::toMilli((string) ($r['qty'] ?? '0'));
            if ($qty <= 0) {
                throw new DomainException("{$product->name}: enter a quantity above zero.");
            }
            if (! $product->track_stock) {
                throw new DomainException("{$product->name} does not track stock.");
            }
            if ($toIndustry && $product->industry_id && $product->industry_id !== $toIndustry) {
                throw new DomainException("{$product->name} is not in your catalogue. Ask for the item as your branch sells it.");
            }
            $lines[] = ['line_no' => $i + 1, 'product_id' => $product->id, 'product_uom_id' => $unit->id, 'sku' => $product->sku, 'name' => $product->name,
                'uom_code' => $unit->uom->code, 'factor' => $unit->factor, 'qty' => Qty::format($qty)];
        }
        if ($lines === []) {
            throw new DomainException('Add at least one line.');
        }
        $cross = $toIndustry && $from->branch->industry_id && $from->branch->industry_id !== $toIndustry;

        return $this->recorder->transaction(function () use ($actor, $data, $from, $to, $lines, $cross) {
            $r = (new StockRequest)->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'stock_request'),
                'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id, 'from_branch_id' => $from->branch_id, 'to_branch_id' => $to->branch_id,
                'cross_industry' => $cross, 'status' => 'requested', 'note' => $data['note'] ?? null, 'requested_by_name' => $actor->name, 'requested_by' => $actor->user?->id]);
            $this->recorder->save($actor, $r, 'stock_request.created', branchId: $to->branch_id);
            foreach ($lines as $l) {
                StockRequestLine::create(['stock_request_id' => $r->id] + $l);
            }
            $this->recorder->outbox->publish($actor, $r->fresh(), 'stock_request.lines_changed'); // the lines travel inside the snapshot

            return $r->fresh('lines');
        });
    }

    /** Between industries: the asking side approves first, then the supplying side. */
    public function approve(Actor $actor, StockRequest $r): StockRequest
    {
        return $this->recorder->transaction(function () use ($actor, $r) {
            $r = StockRequest::lockForUpdate()->findOrFail($r->id);
            $stage = $r->waitingFor();
            if (! in_array($stage, ['requester', 'supplier'], true)) {
                throw new DomainException("{$r->number} does not need an approval now.");
            }
            if ($stage === 'requester') {
                $this->require($actor, 'stock.request.approve', $r->toWarehouse);
                if ($actor->user?->id === $r->requested_by) {
                    throw new DomainException('Someone other than the person who asked must approve it.');
                }
                $r->forceFill(['requester_approved_at' => now(), 'requester_approved_by_name' => $actor->name]);

                return $this->recorder->save($actor, $r, 'stock_request.approved_by_requester', branchId: $r->to_branch_id);
            }
            $this->require($actor, 'stock.request.approve', $r->fromWarehouse);
            $r->forceFill(['supplier_approved_at' => now(), 'supplier_approved_by_name' => $actor->name, 'status' => 'approved']);

            return $this->recorder->save($actor, $r, 'stock_request.approved_by_supplier', branchId: $r->from_branch_id);
        });
    }

    /** Either side says no while it is waiting: the asking side at its approval, the supplier at any time before sending. */
    public function reject(Actor $actor, StockRequest $r, string $reason): StockRequest
    {
        $stage = $r->waitingFor();
        $allowed = match ($stage) {
            'requester' => $this->can($actor, 'stock.request.approve', $r->toWarehouse) || $this->canSupplierSide($actor, $r),
            'supplier', 'supply' => $this->canSupplierSide($actor, $r),
            default => throw new DomainException("{$r->number} is already {$this->label($r)}."),
        };
        if (! $allowed) {
            throw new AccessDenied;
        }
        $r->forceFill(['status' => 'rejected', 'closed_at' => now(), 'closed_reason' => trim($reason) ?: throw new DomainException('Give a reason.')]);

        return $this->recorder->save($actor, $r, 'stock_request.rejected', $reason, $r->from_branch_id);
    }

    public function cancel(Actor $actor, StockRequest $r, string $reason): StockRequest
    {
        $this->require($actor, 'stock.request', $r->toWarehouse);
        if (! in_array($r->status, ['requested', 'approved'], true)) {
            throw new DomainException("{$r->number} is already {$this->label($r)}.");
        }
        $r->forceFill(['status' => 'cancelled', 'closed_at' => now(), 'closed_reason' => trim($reason) ?: null]);

        return $this->recorder->save($actor, $r, 'stock_request.cancelled', $reason, $r->to_branch_id);
    }

    /**
     * Send what was asked for: creates the transfer and dispatches it at once.
     *
     * @param  array<string, array{qty?: string, send_product_id?: ?string}>  $send  request line id => quantity to send (in the asked-for
     *                                                                               unit; missing = as asked, 0 = none) and, between
     *                                                                               industries, the supplier's own product to send
     */
    public function supply(Actor $actor, StockRequest $r, array $send = []): StockRequest
    {
        $from = Warehouse::with('branch')->findOrFail($r->from_warehouse_id);
        $this->require($actor, 'stock.transfer', $from);
        if ($r->waitingFor() !== 'supply') {
            throw new DomainException($r->waitingFor() ? "{$r->number} needs both sides' approval before anything is sent." : "{$r->number} is already {$this->label($r)}.");
        }
        $fromIndustry = $from->branch->industry_id;
        $lines = [];
        $supplied = [];
        foreach ($r->lines()->get() as $l) {
            $product = Product::findOrFail($l->product_id);
            $qty = Qty::toMilli((string) ($send[$l->id]['qty'] ?? $l->qty));
            if ($qty < 0) {
                throw new DomainException('Quantities cannot be negative.');
            }
            $supplied[$l->id] = Qty::format($qty);
            if ($qty === 0) {
                continue;
            }
            $sendAs = $send[$l->id]['send_product_id'] ?? null;
            $needsOwn = $fromIndustry && $product->industry_id && $product->industry_id !== $fromIndustry;
            if ($needsOwn && ! $sendAs) {
                throw new DomainException("{$l->name}: choose which of your products you are sending for it.");
            }
            if ($sendAs && $sendAs !== $product->id) {
                $sending = Product::findOrFail($sendAs);
                $base = ProductUom::where('product_id', $sending->id)->where('is_base', true)->firstOrFail();
                // One base unit for one base unit: a carton of 24 asked for is 24 pieces sent.
                $lines[] = ['product_uom_id' => $base->id, 'qty' => Qty::format(Qty::toBaseMilli($qty, Qty::factorMicro($l->factor))), 'to_product_id' => $product->id];
            } else {
                if ($product->track_serials) {
                    throw new DomainException("{$l->name} is serial-numbered: send it with a transfer that names the serial numbers.");
                }
                $lines[] = ['product_uom_id' => $l->product_uom_id, 'qty' => Qty::format($qty)];
            }
        }
        if ($lines === []) {
            throw new DomainException('Send at least one item, or reject the request.');
        }

        return $this->recorder->transaction(function () use ($actor, $r, $lines, $supplied) {
            $r = StockRequest::lockForUpdate()->findOrFail($r->id);
            $t = $this->transfers->saveDraft($actor, new StockTransfer, ['from_warehouse_id' => $r->from_warehouse_id, 'to_warehouse_id' => $r->to_warehouse_id,
                'note' => "For {$r->number}".($r->note ? ": {$r->note}" : ''), 'lines' => $lines], $r);
            $this->transfers->dispatch($actor, $t);
            foreach ($supplied as $lineId => $q) {
                DB::table('stock_request_lines')->where('id', $lineId)->update(['supplied_qty' => $q]);
            }
            $r->forceFill(['status' => 'supplied', 'stock_transfer_id' => $t->id, 'supplied_at' => now(), 'supplied_by_name' => $actor->name]);

            return $this->recorder->save($actor, $r->load('lines'), 'stock_request.supplied', branchId: $r->from_branch_id);
        });
    }

    private function canSupplierSide(Actor $actor, StockRequest $r): bool
    {
        return $this->can($actor, 'stock.request.approve', $r->fromWarehouse) || $this->can($actor, 'stock.transfer', $r->fromWarehouse);
    }

    private function label(StockRequest $r): string
    {
        return mb_strtolower(StockRequest::STATUSES[$r->status] ?? $r->status);
    }

    private function can(Actor $actor, string $permission, Warehouse $w): bool
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
