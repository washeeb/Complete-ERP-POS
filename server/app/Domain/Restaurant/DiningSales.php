<?php

namespace App\Domain\Restaurant;

use App\Domain\Modules\ModuleService;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\DiningOrder;
use App\Models\DiningOrderItem;
use App\Models\Product;
use App\Models\RecipeLine;
use App\Models\Sale;
use App\Support\Qty;
use App\Support\Settings;
use Illuminate\Support\Str;

/**
 * How a till sale meets the restaurant (owner decisions 2026-10-17): paying a bill's items, the service charge line,
 * ingredients used up when the bill is paid (recipes), and kitchen tickets for counter sales.
 */
final class DiningSales
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly ModuleService $modules,
        private readonly Settings $settings,
        private readonly DocumentNumbers $numbers,
    ) {}

    /** @return array{0: string, 1: int}|null the service-charge product and its rate (basis points), when the branch runs a restaurant */
    public function serviceCharge(string $tenantId, Branch $branch): ?array
    {
        $bp = (int) $this->settings->get('restaurant.service_charge_bp', $tenantId);
        if ($bp <= 0 || ! $this->modules->isEnabled($branch, 'restaurant')) {
            return null;
        }
        $id = Product::where('sku', strtoupper((string) $this->settings->get('restaurant.service_charge_sku', $tenantId)))->where('track_stock', false)->value('id');

        return $id ? [$id, $bp] : null;
    }

    /**
     * The bill a sale pays, and its lines checked against it: each dish paid once, in full, with its priced extras.
     *
     * @param  list<array{product: Product, unit: mixed, qty: int, dining_item: ?string, service: bool}>  $lines
     */
    public function checkPayment(array $p, array $lines, Branch $branch, bool $offline, array &$flags): ?DiningOrder
    {
        $itemLines = array_values(array_filter($lines, fn ($l) => $l['dining_item'] !== null));
        if (empty($p['dining_order_id'])) {
            if ($itemLines) {
                throw new DomainException('These items belong to a bill; pay them from the bill.', 'bill_changed');
            }

            return null;
        }
        $order = DiningOrder::where('branch_id', $branch->id)->find($p['dining_order_id'])
            ?? throw new DomainException('That bill is not known at this branch. Refresh the floor plan.', 'bill_changed');
        if ($order->status !== 'open' && ! $offline) {
            throw new DomainException("{$order->number} is already closed.", 'order_closed');
        }
        $items = DiningOrderItem::where('dining_order_id', $order->id)->whereIn('id', array_column($itemLines, 'dining_item'))->get()->keyBy('id');
        $problems = [];
        $paidMain = [];
        $extras = [];
        foreach ($itemLines as $l) {
            $item = $items[$l['dining_item']] ?? throw new DomainException('An item is not on this bill. Refresh the bill.', 'bill_changed');
            if ($item->product_id === $l['product']->id && $item->product_uom_id === $l['unit']->id) {
                if (isset($paidMain[$item->id])) {
                    throw new DomainException("{$item->name} is on the sale twice.", 'bill_changed');
                }
                $paidMain[$item->id] = true;
            } elseif (in_array($l['product']->id, array_column($item->modifiers ?? [], 'product_id'), true)) {
                $extras[$item->id][] = $l['product']->id;
            } else {
                throw new DomainException("{$l['product']->name} is not part of {$item->name}.", 'bill_changed');
            }
            if ($l['qty'] !== Qty::toMilli((string) $item->qty)) {
                throw new DomainException("{$item->name}: pay the whole item ({$item->qty}).", 'bill_changed');
            }
            if ($item->status === 'void' || $item->sale_id !== null) {
                $problems[] = "{$item->name} was ".($item->status === 'void' ? 'voided' : 'already paid');
            }
        }
        foreach (array_keys($paidMain) as $id) {
            $needed = array_values(array_filter(array_column($items[$id]->modifiers ?? [], 'product_id')));
            $given = $extras[$id] ?? [];
            sort($needed);
            sort($given);
            if ($needed !== $given) {
                $problems[] = "{$items[$id]->name}: its extras were not charged as ordered";
            }
        }
        foreach (array_diff(array_keys($extras), array_keys($paidMain)) as $id) {
            $problems[] = "extras of {$items[$id]->name} paid without the dish";
        }
        if ($problems && ! $offline) {
            throw new DomainException('The bill changed: '.implode('; ', $problems).'. Refresh the bill.', 'bill_changed');
        }
        if ($problems) {
            $flags[] = ['dining_conflict', "Paid offline against {$order->number}: ".implode('; ', $problems)];
        }

        return $order;
    }

    /**
     * Stock that leaves with the sale: a dish with a recipe uses up its ingredients (per base unit of the dish);
     * everything else leaves as itself. Service charge lines move no stock.
     */
    public function stockEntries(array $lines): array
    {
        $recipes = RecipeLine::with(['ingredient', 'unit'])->whereIn('product_id', array_map(fn ($l) => $l['product']->id, $lines))->get()->groupBy('product_id');
        $out = [];
        foreach ($lines as $l) {
            if ($l['service']) {
                continue;
            }
            if ($recipe = $recipes[$l['product']->id] ?? null) {
                $dishes = Qty::toBaseMilli($l['qty'], Qty::factorMicro($l['unit']->factor));
                foreach ($recipe as $r) {
                    $out[] = ['product' => $r->ingredient, 'unit' => $r->unit, 'qty_milli' => -Qty::divRound($dishes * Qty::toMilli((string) $r->qty), 1000)];
                }

                continue;
            }
            $out[] = ['product' => $l['product'], 'unit' => $l['unit'], 'qty_milli' => -$l['qty']] + ($l['markdown'] ? ['batch_id' => $l['markdown']->batch_id] : []);
        }

        return $out;
    }

    /**
     * After the sale is recorded (same transaction): mark the bill's items paid and close the bill when nothing is left;
     * or, for a counter sale online at a restaurant branch, send its dishes to the kitchen as a counter order.
     */
    public function settle(Actor $actor, Sale $sale, array $lines, ?DiningOrder $order, Branch $branch, bool $offline, array &$flags): void
    {
        if ($order) {
            $order = DiningOrder::lockForUpdate()->findOrFail($order->id);
            $mains = [];
            foreach ($lines as $l) {
                if ($l['dining_item'] !== null && DiningOrderItem::whereKey($l['dining_item'])->where('product_id', $l['product']->id)->where('product_uom_id', $l['unit']->id)->exists()) {
                    $mains[] = $l['dining_item'];
                }
            }
            $marked = DiningOrderItem::where('dining_order_id', $order->id)->whereIn('id', $mains)->whereNull('sale_id')->where('status', '<>', 'void')->update(['sale_id' => $sale->id]);
            if ($marked !== count($mains) && ! $offline) {
                throw new DomainException('Part of this bill was just paid on another till. Refresh the bill.', 'bill_changed');
            }
            $open = DiningOrderItem::where('dining_order_id', $order->id)->where('status', '<>', 'void')->whereNull('sale_id')->exists();
            if (! $open && $order->status === 'open') {
                $order->forceFill(['status' => 'closed', 'closed_at' => now()]);
                $this->recorder->save($actor, $order, 'dining_order.paid', branchId: $order->branch_id, payload: ['sale_id' => $sale->id]);
            } else {
                $order->forceFill(['version' => $order->version + 1, 'updated_at' => now()])->save();
                $this->recorder->audit->record($actor, 'dining_order.part_paid', $order, ['sale_id' => [null, $sale->id]], null, $order->branch_id);
                $this->recorder->outbox->publish($actor, $order->fresh(), 'dining_order.part_paid');
            }

            return;
        }

        // Counter service: paid at the till, cooked after. Offline the till printed the kitchen ticket itself.
        $dishes = array_values(array_filter($lines, fn ($l) => ! $l['service'] && $l['product']->station));
        if ($offline || $dishes === [] || ! $this->modules->isEnabled($branch, 'restaurant')) {
            return;
        }
        $now = now();
        $counter = (new DiningOrder)->forceFill([
            'id' => (string) Str::uuid7(), 'tenant_id' => $sale->tenant_id, 'number' => $this->numbers->next($sale->tenant_id, 'dining_order'), 'branch_id' => $branch->id,
            'kind' => 'counter', 'customer_id' => $sale->customer_id, 'waiter_id' => $sale->cashier_id, 'waiter_name' => $sale->cashier_name,
            'status' => 'closed', 'opened_at' => $now, 'closed_at' => $now,
        ]);
        $counter->save();
        DiningOrderItem::insert(array_map(fn ($l) => ['id' => (string) Str::uuid7(), 'dining_order_id' => $counter->id, 'product_id' => $l['product']->id,
            'product_uom_id' => $l['unit']->id, 'name' => $l['product']->name, 'qty' => Qty::format($l['qty']), 'modifiers' => null, 'note' => $l['note'],
            'course' => 0, 'seat' => null, 'station' => $l['product']->station, 'status' => 'fired', 'sale_id' => $sale->id, 'added_by_name' => $sale->cashier_name,
            'added_at' => $now, 'fired_at' => $now], $dishes));
        $this->recorder->audit->record($actor, 'dining_order.counter', $counter, ['number' => [null, $counter->number], 'sale_id' => [null, $sale->id]], null, $branch->id);
        $this->recorder->outbox->publish($actor, $counter->fresh(), 'dining_order.counter');
        $sale->forceFill(['dining_order_id' => $counter->id])->save();
    }
}
