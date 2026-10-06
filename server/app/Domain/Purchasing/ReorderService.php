<?php

namespace App\Domain\Purchasing;

use App\Domain\Inventory\TransferService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\ReorderRule;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Qty;
use Illuminate\Support\Facades\DB;

/**
 * Min/max reordering: when what a warehouse has plus what is already coming (open purchase orders and
 * transfers in transit) falls below the minimum, suggest topping it up to the maximum — in the supplier's
 * purchase unit, at least their minimum order — and turn the suggestions into draft purchase orders.
 */
final class ReorderService
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly PurchaseOrderService $orders) {}

    public function saveRule(Actor $actor, Warehouse $warehouse, Product $product, string $min, string $max): ReorderRule
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'warehouse.manage', $warehouse)) {
            throw new AccessDenied;
        }
        $minM = Qty::toMilli($min);
        $maxM = Qty::toMilli($max);
        if ($minM < 0 || $maxM <= 0 || $maxM < $minM) {
            throw new DomainException('The maximum must be above zero and not below the minimum.');
        }
        $rule = ReorderRule::firstOrNew(['warehouse_id' => $warehouse->id, 'product_id' => $product->id]);
        $rule->tenant_id = $warehouse->tenant_id;
        $rule->min_qty = Qty::format($minM);
        $rule->max_qty = Qty::format($maxM);

        return $this->recorder->save($actor, $rule, $rule->exists ? 'reorder_rule.updated' : 'reorder_rule.created', branchId: $warehouse->branch_id);
    }

    /**
     * @return list<array{product_id: string, sku: string, name: string, on_hand: int, on_order: int, in_transit: int, min: int, max: int,
     *   supplier_id: ?string, supplier: ?string, product_uom_id: ?string, uom_code: ?string, suggest_qty: int, unit_cost_minor: ?int}>
     *   quantities in thousandths; on_hand/on_order/in_transit/min/max in base units, suggest_qty in the purchase unit
     */
    public function suggestions(Warehouse $warehouse): array
    {
        $rules = ReorderRule::where('warehouse_id', $warehouse->id)->with('product')->get();
        if ($rules->isEmpty()) {
            return [];
        }
        $onHand = DB::table('stock_levels')->where('warehouse_id', $warehouse->id)->pluck('on_hand', 'product_id');
        $onOrder = DB::table('purchase_order_lines')->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
            ->where('purchase_orders.warehouse_id', $warehouse->id)->whereIn('purchase_orders.status', ['draft', 'pending_approval', 'approved', 'partially_received'])
            ->groupBy('purchase_order_lines.product_id')
            ->selectRaw('purchase_order_lines.product_id, SUM(GREATEST(purchase_order_lines.qty - purchase_order_lines.received_qty, 0) * purchase_order_lines.factor) AS qty')
            ->pluck('qty', 'product_id');
        $transit = TransferService::inTransitTo($warehouse->id);

        $out = [];
        foreach ($rules as $r) {
            $have = Qty::toMilli((string) ($onHand[$r->product_id] ?? '0'));
            $coming = Qty::toMilli(number_format((float) ($onOrder[$r->product_id] ?? 0), 3, '.', ''));
            $moving = $transit[$r->product_id] ?? 0;
            $min = Qty::toMilli((string) $r->min_qty);
            $max = Qty::toMilli((string) $r->max_qty);
            if ($have + $coming + $moving >= $min) {
                continue;
            }
            $need = $max - ($have + $coming + $moving);
            $sp = SupplierService::bestFor($r->product_id);
            $factor = $sp ? Qty::factorMicro((string) $sp->unit->factor) : 1_000_000;
            $units = (int) ceil($need * 1_000_000 / $factor / 1000) * 1000; // whole purchase units
            if ($sp) {
                $units = max($units, Qty::toMilli((string) $sp->min_order_qty));
            }
            $out[] = ['product_id' => $r->product_id, 'sku' => $r->product->sku, 'name' => $r->product->name, 'on_hand' => $have, 'on_order' => $coming,
                'in_transit' => $moving, 'min' => $min, 'max' => $max, 'supplier_id' => $sp?->supplier_id, 'supplier' => $sp?->supplier->name,
                'product_uom_id' => $sp?->product_uom_id, 'uom_code' => $sp?->unit->uom->code, 'suggest_qty' => $units, 'unit_cost_minor' => $sp?->last_cost_minor];
        }

        return $out;
    }

    /**
     * One draft purchase order per supplier from the chosen suggestions (product ids).
     *
     * @return list<PurchaseOrder>
     */
    public function createDrafts(Actor $actor, Warehouse $warehouse, array $productIds): array
    {
        $chosen = array_filter($this->suggestions($warehouse), fn ($s) => in_array($s['product_id'], $productIds, true));
        $missing = array_filter($chosen, fn ($s) => $s['supplier_id'] === null);
        if ($missing) {
            throw new DomainException('No supplier is set up for: '.implode(', ', array_column($missing, 'name')).'. Add them to a supplier first.');
        }
        if ($chosen === []) {
            throw new DomainException('Nothing to order.');
        }

        return $this->recorder->transaction(function () use ($actor, $warehouse, $chosen) {
            $pos = [];
            foreach (collect($chosen)->groupBy('supplier_id') as $supplierId => $rows) {
                $pos[] = $this->orders->saveDraft($actor, new PurchaseOrder, ['supplier_id' => $supplierId, 'warehouse_id' => $warehouse->id,
                    'note' => 'From reorder suggestions', 'lines' => $rows->map(fn ($s) => ['product_uom_id' => $s['product_uom_id'], 'qty' => Qty::format($s['suggest_qty'])])->values()->all()]);
            }

            return $pos;
        });
    }
}
