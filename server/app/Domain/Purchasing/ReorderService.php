<?php

namespace App\Domain\Purchasing;

use App\Domain\Inventory\StockRequestService;
use App\Domain\Inventory\TransferService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\PurchaseOrder;
use App\Models\ReorderRule;
use App\Models\StockRequest;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Qty;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Min/max reordering: when what a warehouse has plus what is already coming (open purchase orders and
 * transfers in transit) falls to the minimum (ReorderLevels: own figure, smart level or the company default), suggest topping it up to the maximum — in the supplier's
 * purchase unit, at least their minimum order — and turn the suggestions into draft purchase orders.
 */
final class ReorderService
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly PurchaseOrderService $orders) {}

    /** A product's own minimum and maximum in a store (base units), or a smart level accepted from Suggested levels. */
    public function saveRule(Actor $actor, Warehouse $warehouse, Product $product, string $min, string $max, string $source = 'own'): ReorderRule
    {
        if (! $actor->user || ! $this->authz->allowsAny($actor->user, ['warehouse.manage', 'stock.levels'], $warehouse)) {
            throw new AccessDenied;
        }

        return $this->store($actor, $warehouse, $product->id, $min, $max, $source);
    }

    /** Back to the company default: the row stays (it replicates), marked as following the default. */
    public function useDefault(Actor $actor, ReorderRule $rule): ReorderRule
    {
        if (! $actor->user || ! $this->authz->allowsAny($actor->user, ['warehouse.manage', 'stock.levels'], $rule->warehouse)) {
            throw new AccessDenied;
        }
        $rule->source = 'default';

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $rule, 'reorder_rule.default', branchId: $rule->warehouse->branch_id));
    }

    /** Write a level without a permission check: smart levels set automatically (system actor) and the callers above. */
    public function store(Actor $actor, Warehouse $warehouse, string $productId, string $min, string $max, string $source): ReorderRule
    {
        $minM = Qty::toMilli($min);
        $maxM = Qty::toMilli($max);
        if ($minM < 0 || $maxM <= 0 || $maxM < $minM) {
            throw new DomainException('The maximum must be above zero and not below the minimum.');
        }
        if (! isset(ReorderLevels::SOURCES[$source])) {
            throw new DomainException('Unknown kind of level.');
        }
        $rule = ReorderRule::firstOrNew(['warehouse_id' => $warehouse->id, 'product_id' => $productId]);
        $rule->tenant_id = $warehouse->tenant_id;
        $rule->min_qty = Qty::format($minM);
        $rule->max_qty = Qty::format($maxM);
        $rule->source = $source;

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $rule, $rule->exists ? 'reorder_rule.updated' : 'reorder_rule.created', branchId: $warehouse->branch_id));
    }

    /**
     * @return list<array{product_id: string, sku: string, name: string, on_hand: int, on_order: int, in_transit: int, min: int, max: int,
     *   supplier_id: ?string, supplier: ?string, product_uom_id: ?string, uom_code: ?string, suggest_qty: int, unit_cost_minor: ?int}>
     *   quantities in thousandths; on_hand/on_order/in_transit/min/max in base units, suggest_qty in the purchase unit
     */
    public function suggestions(Warehouse $warehouse): array
    {
        $levels = app(ReorderLevels::class)->forWarehouse($warehouse);
        if ($levels === []) {
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
        $names = DB::table('products')->whereIn('id', array_keys($levels))->get(['id', 'sku', 'name'])->keyBy('id');
        $low = [];
        foreach ($levels as $productId => $level) {
            $have = Qty::toMilli((string) ($onHand[$productId] ?? '0'));
            if ($have + Qty::toMilli(number_format((float) ($onOrder[$productId] ?? 0), 3, '.', '')) + ($transit[$productId] ?? 0) <= $level['min']) {
                $low[] = $productId;
            }
        }
        $suppliers = SupplierService::bestForMany($low); // one query, not one per product
        $spare = $this->spareElsewhere($warehouse, $low); // stock other stores have above their own maximum
        $base = DB::table('product_uoms as u')->join('uoms', 'uoms.id', '=', 'u.uom_id')->whereIn('u.product_id', $low)->where('u.is_base', true)
            ->get(['u.product_id', 'u.id', 'uoms.code'])->keyBy('product_id');
        foreach ($levels as $productId => $level) {
            $have = Qty::toMilli((string) ($onHand[$productId] ?? '0'));
            $coming = Qty::toMilli(number_format((float) ($onOrder[$productId] ?? 0), 3, '.', ''));
            $moving = $transit[$productId] ?? 0;
            ['min' => $min, 'max' => $max] = $level;
            if ($have + $coming + $moving > $min) { // at the minimum counts as low
                continue;
            }
            $need = max($max - ($have + $coming + $moving), 1);
            // Move first, buy the rest (owner request 2026-10-24): whole base units from the store with the most to spare.
            $from = $spare[$productId] ?? null;
            $move = $from ? (int) (intdiv(min($from['qty'], $need), 1000) * 1000) : 0;
            $buy = $need - $move;
            $sp = $suppliers[$productId] ?? null;
            $factor = $sp ? Qty::factorMicro((string) $sp->unit->factor) : 1_000_000;
            $units = $buy > 0 ? (int) ceil($buy * 1_000_000 / $factor / 1000) * 1000 : 0; // whole purchase units
            if ($sp && $units > 0) {
                $units = max($units, Qty::toMilli((string) $sp->min_order_qty));
                $step = Qty::toMilli((string) $sp->order_multiple);
                if ($step > 0 && $units % $step !== 0) {
                    $units = (intdiv($units, $step) + 1) * $step;
                }
            }
            $out[] = ['product_id' => $productId, 'sku' => $names[$productId]->sku, 'name' => $names[$productId]->name, 'on_hand' => $have, 'on_order' => $coming,
                'in_transit' => $moving, 'min' => $min, 'max' => $max, 'source' => $level['source'], 'supplier_id' => $sp?->supplier_id, 'supplier' => $sp?->supplier->name,
                'product_uom_id' => $sp?->product_uom_id, 'uom_code' => $sp?->unit->uom->code, 'suggest_qty' => $units, 'unit_cost_minor' => $sp?->last_cost_minor,
                'move_qty' => $move, 'move_from_id' => $move ? $from['warehouse_id'] : null, 'move_from' => $move ? $from['name'] : null,
                'base_uom_id' => $base[$productId]->id ?? null, 'base_uom_code' => $base[$productId]->code ?? null];
        }

        return $out;
    }

    /**
     * For each product, the other store (of the same line of business) with the most stock above its own maximum — what
     * it can spare without falling short itself. Off with System → Settings → Stock → Suggest moves between stores.
     *
     * @param  list<string>  $productIds
     * @return array<string, array{warehouse_id: string, name: string, qty: int}> qty in base milli-units
     */
    public function spareElsewhere(Warehouse $warehouse, array $productIds): array
    {
        if ($productIds === [] || ! app(Settings::class)->get('purchasing.suggest_moves', $warehouse->tenant_id)) {
            return [];
        }
        $industry = DB::table('branches')->where('id', $warehouse->branch_id)->value('industry_id');
        $others = Warehouse::withoutGlobalScopes()->with('branch')->where('tenant_id', $warehouse->tenant_id)->where('is_active', true)->where('id', '!=', $warehouse->id)->get()
            ->filter(fn (Warehouse $w) => ! $industry || ! $w->branch?->industry_id || $w->branch->industry_id === $industry); // across industries needs two approvals: not suggested
        $stock = DB::table('stock_levels')->whereIn('warehouse_id', $others->pluck('id'))->whereIn('product_id', $productIds)->where('on_hand', '>', 0)
            ->get(['warehouse_id', 'product_id', 'on_hand'])->groupBy('warehouse_id');
        $best = [];
        foreach ($others as $w) {
            $rows = $stock[$w->id] ?? collect();
            if ($rows->isEmpty()) {
                continue;
            }
            $levels = app(ReorderLevels::class)->forWarehouse($w, $rows->pluck('product_id')->all());
            foreach ($rows as $r) {
                $max = $levels[$r->product_id]['max'] ?? null;
                if ($max === null) {
                    continue; // not in that store's range
                }
                $spare = Qty::toMilli((string) $r->on_hand) - $max;
                if ($spare >= 1000 && $spare > ($best[$r->product_id]['qty'] ?? 0)) {
                    $best[$r->product_id] = ['warehouse_id' => $w->id, 'name' => $w->name.($w->branch ? ' ('.$w->branch->name.')' : ''), 'qty' => $spare];
                }
            }
        }

        return $best;
    }

    /**
     * Ask the stores with stock to spare to send it (one stock request per store), for the chosen lines.
     *
     * @return list<StockRequest>
     */
    public function requestMoves(Actor $actor, Warehouse $warehouse, array $productIds): array
    {
        $chosen = array_filter($this->suggestions($warehouse), fn ($s) => in_array($s['product_id'], $productIds, true) && $s['move_qty'] > 0);
        if ($chosen === []) {
            throw new DomainException('No other store has these to spare.');
        }

        return $this->recorder->transaction(fn () => collect($chosen)->groupBy('move_from_id')->map(fn ($rows, $fromId) => app(StockRequestService::class)->request($actor, [
            'from_warehouse_id' => $fromId, 'to_warehouse_id' => $warehouse->id, 'note' => 'From What to order: they have more than their maximum.',
            'lines' => $rows->map(fn ($s) => ['product_uom_id' => $s['base_uom_id'], 'qty' => Qty::format($s['move_qty'])])->values()->all(),
        ]))->values()->all());
    }

    /**
     * A suggestion as the buyer changed it on "What to order": another supplier, unit or quantity (owner request 2026-10-22).
     *
     * @param  array{supplier_id?: ?string, product_uom_id?: ?string, qty?: ?string, cost?: ?string}  $change  qty and cost in the chosen unit
     */
    public static function change(array $s, array $change): array
    {
        if (! empty($change['supplier_id'])) {
            $supplier = Supplier::where('id', $change['supplier_id'])->where('is_active', true)->first() ?? throw new DomainException("{$s['name']}: that supplier is not active.");
            [$s['supplier_id'], $s['supplier']] = [$supplier->id, $supplier->name];
        }
        if (! empty($change['product_uom_id'])) {
            $unit = ProductUom::with('uom')->where('id', $change['product_uom_id'])->where('product_id', $s['product_id'])->first()
                ?? throw new DomainException("{$s['name']}: choose one of its own units.");
            [$s['product_uom_id'], $s['uom_code']] = [$unit->id, $unit->uom->code];
        }
        if (isset($change['qty']) && $change['qty'] !== '') {
            $qty = Qty::toMilli((string) $change['qty']);
            if ($qty <= 0) {
                throw new DomainException("{$s['name']}: enter a quantity above zero.");
            }
            $s['suggest_qty'] = $qty;
        }
        if (! $s['product_uom_id']) { // no supplier was set up: the suggestion is in the base unit
            $base = ProductUom::with('uom')->where('product_id', $s['product_id'])->where('is_base', true)->first();
            [$s['product_uom_id'], $s['uom_code']] = [$base?->id, $base?->uom->code];
        }
        if ($s['supplier_id'] && $s['product_uom_id']) {
            $s['unit_cost_minor'] = SupplierProduct::where('supplier_id', $s['supplier_id'])->where('product_uom_id', $s['product_uom_id'])->value('last_cost_minor');
        }
        $s['cost'] = isset($change['cost']) && $change['cost'] !== '' ? (string) $change['cost'] : null; // typed by the buyer: wins over the supplier's last cost

        return $s + ['changed' => true];
    }

    /**
     * One draft purchase order per supplier from the chosen suggestions (product ids), with the buyer's changes.
     *
     * @return list<PurchaseOrder>
     */
    public function createDrafts(Actor $actor, Warehouse $warehouse, array $productIds, array $changes = []): array
    {
        $chosen = array_map(fn ($s) => isset($changes[$s['product_id']]) ? self::change($s, $changes[$s['product_id']]) : $s,
            array_filter($this->suggestions($warehouse), fn ($s) => in_array($s['product_id'], $productIds, true)));
        $chosen = array_filter($chosen, fn ($s) => $s['suggest_qty'] > 0); // covered by a move from another store: nothing to buy
        if ($chosen === []) {
            throw new DomainException('Everything chosen comes from other stores. Use “Ask other stores to send”.');
        }
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
                    'note' => 'From reorder suggestions', 'lines' => $rows->map(fn ($s) => ['product_uom_id' => $s['product_uom_id'], 'qty' => Qty::format($s['suggest_qty']), 'unit_cost' => $s['cost'] ?? null])->values()->all()]);
            }

            return $pos;
        });
    }
}
