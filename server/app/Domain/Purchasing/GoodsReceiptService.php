<?php

namespace App\Domain\Purchasing;

use App\Domain\Inventory\StockLedger;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Domain\Sync\ReviewQueue;
use App\Models\Bin;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
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
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Goods received notes. Posting adds the goods to stock at their cost (moving the weighted average), into
 * the receiving bin unless another bin is given, and advances the purchase order. A delivery more than the
 * over-delivery tolerance above the order is held until a manager approves it (owner decision: 5 %).
 * Posted notes never change; a wrong one is reversed as a whole.
 */
final class GoodsReceiptService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly StockLedger $ledger,
        private readonly Settings $settings,
        private readonly ReviewQueue $reviews,
    ) {}

    /**
     * @param array{warehouse_id?: string, supplier_id?: string, supplier_ref?: ?string, note?: ?string, client_id?: ?string,
     *   lines: list<array{purchase_order_line_id?: ?string, product_uom_id?: ?string, qty: string, unit_cost?: ?string, bin_id?: ?string}>} $data
     *   client_id: a handheld's id for this delivery, so a resend never receives twice
     */
    public function receive(Actor $actor, ?PurchaseOrder $po, array $data): GoodsReceipt
    {
        $warehouse = $po ? $po->warehouse : Warehouse::findOrFail($data['warehouse_id'] ?? null);
        $this->require($actor, 'stock.receive', $warehouse);
        if (! empty($data['client_id']) && ($done = GoodsReceipt::find($data['client_id']))) {
            return $done; // already received (the handheld lost the answer and sent again)
        }
        if ($po && ! $po->isReceivable()) {
            throw new DomainException("Purchase order {$po->number} is ".(PurchaseOrder::STATUSES[$po->status] ?? $po->status).'; it cannot be received.', 'wrong_status');
        }
        $manager = $this->authz->allows($actor->user, 'stock.receive.approve', $warehouse);
        if (! $po && ! $manager) {
            throw new DomainException('Goods without a purchase order can only be received by a manager.', 'no_po');
        }
        $supplier = $po ? $po->supplier : Supplier::findOrFail($data['supplier_id'] ?? null);
        $currency = Tenant::find($actor->tenantId)->currency_code;
        $tolerance = (int) $this->settings->get('purchasing.over_receipt_pct', $actor->tenantId);

        $lines = [];
        $over = [];
        $poLines = $po ? $po->lines()->get()->keyBy('id') : collect();
        $thisDelivery = [];
        foreach (array_values($data['lines'] ?? []) as $i => $r) {
            $qty = Qty::toMilli((string) ($r['qty'] ?? '0'));
            if ($qty === 0) {
                continue;
            }
            if ($qty < 0) {
                throw new DomainException('Quantities received cannot be negative. Reverse the goods received note instead.');
            }
            $poLine = null;
            if (! empty($r['purchase_order_line_id'])) {
                $poLine = $poLines[$r['purchase_order_line_id']] ?? throw new DomainException('That line is not on this purchase order.');
                $unit = ProductUom::with('uom')->findOrFail($poLine->product_uom_id);
            } else {
                $unit = ProductUom::with('uom')->find($r['product_uom_id'] ?? null) ?? throw new DomainException('Choose the product and unit received.');
                if ($po) {
                    $poLine = $poLines->first(fn ($l) => $l->product_uom_id === $unit->id); // scanned item on the order
                    if (! $poLine) {
                        throw new DomainException(Product::find($unit->product_id)?->name.' is not on purchase order '.$po->number.'.', 'not_on_order');
                    }
                }
            }
            $product = Product::findOrFail($unit->product_id);
            $cost = isset($r['unit_cost']) && $r['unit_cost'] !== '' && $r['unit_cost'] !== null
                ? Money::toMinor((string) $r['unit_cost'], $currency)
                : ($poLine?->unit_cost_minor ?? SupplierProduct::where('supplier_id', $supplier->id)->where('product_uom_id', $unit->id)->value('last_cost_minor'));
            if ($cost === null || $cost < 0) {
                throw new DomainException("{$product->name}: enter the cost.");
            }
            if (! empty($r['bin_id']) && ! Bin::where('warehouse_id', $warehouse->id)->where('is_active', true)->whereKey($r['bin_id'])->exists()) {
                throw new DomainException('That bin is not in this warehouse.');
            }
            if ($poLine) {
                $thisDelivery[$poLine->id] = ($thisDelivery[$poLine->id] ?? 0) + $qty;
                $ordered = Qty::toMilli((string) $poLine->qty);
                $after = Qty::toMilli((string) $poLine->received_qty) + $thisDelivery[$poLine->id];
                if ($after * 100 > $ordered * (100 + $tolerance)) {
                    $over[$poLine->id] = "{$product->name}: ordered ".Qty::format($ordered).', receiving '.Qty::format($after)." {$unit->uom->code}";
                }
            }
            $lines[] = ['line_no' => count($lines) + 1, 'purchase_order_line_id' => $poLine?->id, 'product_id' => $product->id, 'product_uom_id' => $unit->id,
                'sku' => $product->sku, 'name' => $product->name, 'uom_code' => $unit->uom->code, 'factor' => $unit->factor, 'qty' => Qty::format($qty),
                'unit_cost_minor' => $cost, 'net_minor' => Qty::divRound($qty * $cost, 1000), 'bin_id' => $r['bin_id'] ?? null];
        }
        if ($lines === []) {
            throw new DomainException('Enter what was received.');
        }
        $held = $over !== [] && ! $manager;

        return $this->recorder->transaction(function () use ($actor, $po, $data, $warehouse, $supplier, $currency, $lines, $over, $held) {
            $grn = new GoodsReceipt;
            if (! empty($data['client_id'])) {
                $grn->id = $data['client_id'];
            }
            $grn->forceFill([
                'tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'goods_receipt'),
                'purchase_order_id' => $po?->id, 'supplier_id' => $supplier->id, 'branch_id' => $warehouse->branch_id, 'warehouse_id' => $warehouse->id,
                'status' => 'held', 'supplier_ref' => $data['supplier_ref'] ?? null, 'note' => $data['note'] ?? null,
                'currency_code' => $currency, 'net_minor' => array_sum(array_column($lines, 'net_minor')),
                'received_by' => $actor->id, 'received_by_name' => $actor->name, 'received_at' => now(),
                'approved_by' => $over && ! $held ? $actor->id : null, 'approved_by_name' => $over && ! $held ? $actor->name : null,
            ])->save();
            foreach ($lines as $l) {
                GoodsReceiptLine::create(['goods_receipt_id' => $grn->id] + $l);
            }
            if ($held) {
                $this->recorder->audit->record($actor, 'goods_receipt.held', $grn, ['over' => [null, array_values($over)]], null, $grn->branch_id);
                $this->reviews->add($actor, 'grn_held', 'goods_receipt', $grn->id,
                    "{$grn->number} from {$supplier->name} is above the ordered quantity — a manager must accept or refuse it: ".implode('; ', $over), [], $grn->branch_id);
                $this->recorder->outbox->publish($actor, $grn->load('lines'), 'goods_receipt.held');

                return $grn;
            }

            return $this->post($actor, $grn, 'goods_receipt.posted');
        });
    }

    /** A manager accepts a held delivery (above tolerance); it is then posted to stock. */
    public function approveHeld(Actor $actor, GoodsReceipt $grn): GoodsReceipt
    {
        $this->require($actor, 'stock.receive.approve', $grn->warehouse);
        if ($grn->status !== 'held') {
            throw new DomainException("{$grn->number} is not waiting for approval.");
        }

        return $this->recorder->transaction(function () use ($actor, $grn) {
            $grn->forceFill(['approved_by' => $actor->id, 'approved_by_name' => $actor->name]);
            $this->reviews->closeBySystem('goods_receipt', $grn->id, "Accepted by {$actor->name}");

            return $this->post($actor, $grn, 'goods_receipt.approved');
        });
    }

    /** A manager refuses a held delivery: nothing enters stock and the note is kept as reversed. */
    public function refuseHeld(Actor $actor, GoodsReceipt $grn, string $reason): GoodsReceipt
    {
        $this->require($actor, 'stock.receive.approve', $grn->warehouse);
        if ($grn->status !== 'held') {
            throw new DomainException("{$grn->number} is not waiting for approval.");
        }

        return $this->recorder->transaction(function () use ($actor, $grn, $reason) {
            $grn->forceFill(['status' => 'reversed', 'reversed_at' => now(), 'reversed_by_name' => $actor->name, 'reversal_reason' => mb_substr(trim($reason) ?: 'Refused', 0, 255)]);
            $this->reviews->closeBySystem('goods_receipt', $grn->id, "Refused by {$actor->name}: {$reason}");

            return $this->recorder->save($actor, $grn, 'goods_receipt.refused', $reason, $grn->branch_id);
        });
    }

    /** Takes the goods back out of stock (from the bins they went to) and reopens the purchase order quantities. */
    public function reverse(Actor $actor, GoodsReceipt $grn, string $reason): GoodsReceipt
    {
        $this->require($actor, 'stock.receive.approve', $grn->warehouse);
        if ($grn->status !== 'posted') {
            throw new DomainException("Only a posted goods received note can be reversed ({$grn->number} is ".(GoodsReceipt::STATUSES[$grn->status] ?? $grn->status).').');
        }
        if (trim($reason) === '') {
            throw new DomainException('Give a reason.');
        }

        return $this->recorder->transaction(function () use ($actor, $grn, $reason) {
            $grn = GoodsReceipt::lockForUpdate()->findOrFail($grn->id);
            $receiptMoves = DB::table('stock_movements')->where('source_type', 'goods_receipt')->where('source_id', $grn->id)->where('type', 'receipt')->get();
            $entries = [];
            foreach ($receiptMoves as $m) {
                $unit = ProductUom::where('product_id', $m->product_id)->where('uom_id', $m->uom_id)->firstOrFail();
                $entries[] = ['product' => Product::findOrFail($m->product_id), 'unit' => $unit, 'qty_milli' => -Qty::toMilli((string) $m->qty_entered),
                    'bin_id' => $m->bin_id, 'unit_cost_minor' => $m->unit_cost_minor];
            }
            $this->ledger->post($actor, $grn->warehouse, 'receipt_reversal', $entries, "Reversal of {$grn->number}: {$reason}", 'goods_receipt', $grn->id);
            if ($grn->purchase_order_id) {
                $this->advanceOrder($actor, $grn, -1);
            }
            $grn->forceFill(['status' => 'reversed', 'reversed_at' => now(), 'reversed_by_name' => $actor->name, 'reversal_reason' => mb_substr(trim($reason), 0, 255)]);

            return $this->recorder->save($actor, $grn, 'goods_receipt.reversed', $reason, $grn->branch_id);
        });
    }

    private function post(Actor $actor, GoodsReceipt $grn, string $action): GoodsReceipt
    {
        $entries = [];
        foreach ($grn->lines()->get() as $l) {
            $entries[] = ['product' => Product::findOrFail($l->product_id), 'unit' => ProductUom::findOrFail($l->product_uom_id),
                'qty_milli' => Qty::toMilli((string) $l->qty), 'unit_cost_minor' => $l->unit_cost_minor, 'bin_id' => $l->bin_id];
            SupplierProduct::where('supplier_id', $grn->supplier_id)->where('product_uom_id', $l->product_uom_id)->update(['last_cost_minor' => $l->unit_cost_minor]);
        }
        $this->ledger->post($actor, $grn->warehouse, 'receipt', $entries, "{$grn->number} from ".$grn->supplier->name, 'goods_receipt', $grn->id);
        if ($grn->purchase_order_id) {
            $this->advanceOrder($actor, $grn, 1);
        }
        $grn->forceFill(['status' => 'posted', 'posted_at' => now()]);
        $this->recorder->save($actor, $grn->load('lines'), $action, branchId: $grn->branch_id);

        return $grn->fresh('lines');
    }

    private function advanceOrder(Actor $actor, GoodsReceipt $grn, int $sign): void
    {
        $po = PurchaseOrder::lockForUpdate()->findOrFail($grn->purchase_order_id);
        foreach ($grn->lines()->whereNotNull('purchase_order_line_id')->get() as $l) {
            $line = PurchaseOrderLine::findOrFail($l->purchase_order_line_id);
            $line->received_qty = Qty::format(Qty::toMilli((string) $line->received_qty) + $sign * Qty::toMilli((string) $l->qty));
            $line->save();
        }
        $event = $sign > 0 ? 'purchase_order.received' : 'purchase_order.receipt_reversed';
        if (! in_array($po->status, ['closed', 'cancelled'], true)) {
            $po->status = PurchaseOrderService::statusFromLines($po);
        }
        if ($po->isDirty()) {
            $this->recorder->save($actor, $po, $event, branchId: $po->branch_id);
        } else {
            // Status unchanged but received quantities moved: other nodes need the new snapshot (the GRN is the audit record).
            $this->recorder->outbox->publish($actor, $po->fresh(), $event);
        }
    }

    private function require(Actor $actor, string $permission, Warehouse $warehouse): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, $permission, $warehouse)) {
            throw new AccessDenied;
        }
    }
}
