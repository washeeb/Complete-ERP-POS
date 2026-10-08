<?php

namespace App\Domain\Manufacturing;

use App\Models\Bom;
use App\Models\WorkCentre;
use App\Models\WorkOrder;
use App\Support\Qty;
use Illuminate\Support\Facades\DB;

/**
 * Material requirements planning (MRP) for one warehouse: suggestions only, nothing is ordered by itself.
 *
 * Demand: open customer orders, reorder levels (min → max) and the materials open work orders still need.
 * Supply: stock on hand, output still to come from open work orders, approved purchase orders not yet received.
 * Products are netted level by level down the bills of materials (finished goods first, then sub-assemblies, then bought
 * materials), so a shortage of a finished product becomes demand for its materials.
 */
final class MrpPlanner
{
    /**
     * @return array{make: list<array<string, mixed>>, buy: list<array<string, mixed>>, load: list<array<string, mixed>>}
     */
    public function plan(string $warehouseId): array
    {
        $boms = Bom::with('lines.unit')->where('status', 'active')->get()->keyBy('product_id');
        $children = []; // product => [material => base per base unit]
        foreach ($boms as $productId => $bom) {
            foreach ($bom->lines->where('kind', 'material') as $l) {
                $children[$productId][$l->product_id] = (float) $l->qty * (float) $l->unit->factor * (1 + $l->scrap_bp / 10000) / max((float) $bom->output_qty, 0.001);
            }
        }
        $level = $this->levels($children);

        $onHand = DB::table('stock_levels')->where('warehouse_id', $warehouseId)->pluck('on_hand', 'product_id')->map(fn ($v) => (float) $v);
        $demand = DB::table('customer_order_lines as l')->join('customer_orders as o', 'o.id', '=', 'l.customer_order_id')
            ->where('o.warehouse_id', $warehouseId)->whereIn('o.status', ['confirmed', 'on_hold', 'partially_delivered'])
            ->groupBy('l.product_id')->selectRaw('l.product_id, SUM(GREATEST(l.qty - l.delivered_qty, 0) * l.factor) AS q')->pluck('q', 'product_id')->map(fn ($v) => (float) $v)->all();
        $onOrder = DB::table('purchase_order_lines as l')->join('purchase_orders as o', 'o.id', '=', 'l.purchase_order_id')
            ->where('o.warehouse_id', $warehouseId)->whereIn('o.status', ['approved', 'partially_received'])
            ->groupBy('l.product_id')->selectRaw('l.product_id, SUM(GREATEST(l.qty - l.received_qty, 0) * l.factor) AS q')->pluck('q', 'product_id')->map(fn ($v) => (float) $v)->all();
        $rules = DB::table('reorder_rules')->where('warehouse_id', $warehouseId)->get(['product_id', 'min_qty', 'max_qty'])->keyBy('product_id');

        // Open work orders: output still to come (supply) and materials still to be used (demand).
        $coming = [];
        $dependent = [];
        foreach (WorkOrder::with('materials')->where('warehouse_id', $warehouseId)->whereIn('status', WorkOrder::OPEN)->get() as $wo) {
            $left = max(0, (float) $wo->qty_planned - (float) $wo->qty_done - (float) $wo->qty_scrapped);
            $coming[$wo->product_id] = ($coming[$wo->product_id] ?? 0) + $left;
            if ($wo->status === 'draft') {
                foreach ($children[$wo->product_id] ?? [] as $child => $per) {
                    $dependent[$child] = ($dependent[$child] ?? 0) + $per * (float) $wo->qty_planned;
                }
            } else {
                foreach ($wo->materials->where('kind', 'material') as $m) {
                    $dependent[$m->product_id] = ($dependent[$m->product_id] ?? 0) + max(0, (float) $m->planned_qty - (float) $m->issued_qty);
                }
            }
        }

        $products = DB::table('products')->whereIn('id', array_keys($level))->get(['id', 'sku', 'name'])->keyBy('id');
        $make = [];
        $buy = [];
        $ids = array_keys($level);
        usort($ids, fn ($a, $b) => [$level[$a], $products[$a]->name ?? ''] <=> [$level[$b], $products[$b]->name ?? '']);
        foreach ($ids as $id) {
            $gross = ($demand[$id] ?? 0) + ($dependent[$id] ?? 0);
            $made = isset($boms[$id]);
            $available = ($onHand[$id] ?? 0) + ($made ? ($coming[$id] ?? 0) : ($onOrder[$id] ?? 0));
            $projected = $available - $gross;
            $need = max(0, -$projected);
            if (($rule = $rules[$id] ?? null) && $projected < (float) $rule->min_qty) {
                $need = max($need, (float) $rule->max_qty - $projected);
            }
            $need = ceil(round($need, 3) * 1000) / 1000;
            if ($need <= 0) {
                continue;
            }
            $row = ['product_id' => $id, 'sku' => $products[$id]->sku, 'name' => $products[$id]->name, 'level' => $level[$id],
                'demand' => round($gross, 3), 'on_hand' => round($onHand[$id] ?? 0, 3), 'suggested' => $need];
            if ($made) {
                $make[] = $row + ['coming' => round($coming[$id] ?? 0, 3), 'bom' => $boms[$id]->code.' rev '.$boms[$id]->revision];
                foreach ($children[$id] ?? [] as $child => $per) {
                    $dependent[$child] = ($dependent[$child] ?? 0) + $per * $need;
                }
            } else {
                $buy[] = $row + ['on_order' => round($onOrder[$id] ?? 0, 3)];
            }
        }

        return ['make' => $make, 'buy' => $buy, 'load' => $this->load($warehouseId)];
    }

    /** Low-level code: 0 for finished goods, one more than the deepest product using it. @return array<string, int> */
    private function levels(array $children): array
    {
        $level = [];
        foreach ($children as $parent => $kids) {
            $level[$parent] ??= 0;
            foreach ($kids as $kid => $_) {
                $level[$kid] ??= 0;
            }
        }
        for ($pass = 0, $changed = true; $changed && $pass < 25; $pass++) {
            $changed = false;
            foreach ($children as $parent => $kids) {
                foreach ($kids as $kid => $_) {
                    if ($level[$kid] < $level[$parent] + 1) {
                        $level[$kid] = $level[$parent] + 1;
                        $changed = true;
                    }
                }
            }
        }

        return $level;
    }

    /** Hours of work still planned on each work centre (open orders at this warehouse) against its daily capacity. */
    private function load(string $warehouseId): array
    {
        $hours = [];
        foreach (WorkOrder::where('warehouse_id', $warehouseId)->whereIn('status', WorkOrder::OPEN)->get() as $wo) {
            $ops = $wo->operations ?? [];
            if ($wo->status === 'draft') { // not released yet: planned from the BOM
                $bom = Bom::find($wo->bom_id);
                $ops = array_map(fn ($op) => $op + ['planned_minutes' => $op['setup_minutes'] + $op['run_minutes'] * (float) $wo->qty_planned / max((float) $bom->output_qty, 0.001),
                    'actual_minutes' => 0], $bom->operations ?? []);
            }
            foreach ($ops as $op) {
                $hours[$op['work_centre_id']] = ($hours[$op['work_centre_id']] ?? 0) + max(0, $op['planned_minutes'] - $op['actual_minutes']) / 60;
            }
        }

        return WorkCentre::whereIn('id', array_keys($hours))->orderBy('code')->get()->map(fn (WorkCentre $c) => ['code' => $c->code, 'name' => $c->name,
            'hours' => round($hours[$c->id], 1), 'per_day' => (float) $c->hours_per_day,
            'days' => (float) $c->hours_per_day > 0 ? round($hours[$c->id] / (float) $c->hours_per_day, 1) : null])->all();
    }

    /** Format a planned quantity for display. */
    public static function qty(float $v): string
    {
        return Qty::format((int) round($v * 1000));
    }
}
