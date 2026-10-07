<?php

namespace App\Domain\Restaurant;

use App\Domain\Modules\ModuleService;
use App\Domain\Pos\SupervisorApproval;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Device;
use App\Models\DiningOrder;
use App\Models\DiningOrderItem;
use App\Models\DiningTable;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\SalePayment;
use App\Models\User;
use App\Security\Authorizer;
use App\Support\Qty;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Dining orders (owner decision 2026-10-17: table service and counter service, kitchen screens with a printer fallback).
 * Orders live on the server so tills, waiter tablets and kitchen screens share them; items are added when the waiter
 * sends them, go to the kitchen at once or wait for their course to be called, and are paid by ordinary till sales.
 */
final class DiningService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly ModuleService $modules,
        private readonly SupervisorApproval $approvals,
    ) {}

    /** @param array{id: string, kind: string, dining_table_id?: ?string, tab_name?: ?string, covers?: int, customer_id?: ?string, note?: ?string, platform?: ?string, external_ref?: ?string} $data */
    public function open(Device $device, User $user, array $data): DiningOrder
    {
        $branch = $this->branch($device, $user, 'restaurant.serve');
        if ($existing = DiningOrder::find($data['id'] ?? null)) {
            return $existing->load('items'); // the till retried
        }
        $kind = $data['kind'] ?? '';
        if (! in_array($kind, ['table', 'tab', 'counter', 'delivery'], true)) {
            throw new DomainException('Choose a table, a bar tab, a counter or a delivery order.');
        }
        // A delivery-app order keyed in at the till: the platform and its order number (each entered once).
        $platform = $kind === 'delivery' ? (string) ($data['platform'] ?? '') : null;
        $ref = $kind === 'delivery' ? strtoupper(trim((string) ($data['external_ref'] ?? ''))) : null;
        if ($kind === 'delivery') {
            if (! array_key_exists($platform, SalePayment::PLATFORMS) || strlen($ref) < 2 || strlen($ref) > 80) {
                throw new DomainException('Choose the delivery app and enter its order number.');
            }
            if (DiningOrder::where('platform', $platform)->where('external_ref', $ref)->exists()) {
                throw new DomainException(SalePayment::PLATFORMS[$platform]." order {$ref} is already in.", 'duplicate');
            }
        }
        $table = null;
        if ($kind === 'table') {
            $table = DiningTable::where('branch_id', $branch->id)->where('is_active', true)->find($data['dining_table_id'] ?? null)
                ?? throw new DomainException('Choose a table on this branch’s floor plan.');
            if ($busy = DiningOrder::where('dining_table_id', $table->id)->where('status', 'open')->first()) {
                throw new DomainException("Table {$table->code} already has an open bill ({$busy->number}).", 'table_busy');
            }
        }
        $tab = $kind === 'tab' ? trim((string) ($data['tab_name'] ?? '')) : ($kind === 'delivery' ? SalePayment::PLATFORMS[$platform]." #{$ref}" : null);
        if ($kind === 'tab' && ($tab === '' || mb_strlen($tab) > 60)) {
            throw new DomainException('Enter the name the tab is opened under.');
        }
        $customer = ! empty($data['customer_id']) ? Customer::find($data['customer_id']) ?? throw new DomainException('That customer is not known here.') : null;
        $actor = Actor::user($user, 'pos', $device->id);

        try {
            return $this->recorder->transaction(function () use ($actor, $device, $user, $branch, $data, $kind, $table, $tab, $customer, $platform, $ref) {
                $o = (new DiningOrder)->forceFill([
                    'id' => $data['id'], 'tenant_id' => $device->tenant_id, 'number' => $this->numbers->next($device->tenant_id, 'dining_order'),
                    'branch_id' => $branch->id, 'kind' => $kind, 'dining_table_id' => $table?->id, 'tab_name' => $tab,
                    'covers' => max(0, min(99, (int) ($data['covers'] ?? 0))), 'customer_id' => $customer?->id, 'waiter_id' => $user->id, 'waiter_name' => $user->name,
                    'status' => 'open', 'note' => isset($data['note']) ? mb_substr(trim((string) $data['note']), 0, 255) ?: null : null, 'opened_at' => now(),
                    'platform' => $platform, 'external_ref' => $ref,
                ]);
                $this->recorder->save($actor, $o, 'dining_order.opened', branchId: $branch->id);

                return $o->load('items');
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('That table was just taken by another waiter. Refresh the floor plan.', 'table_busy');
        }
    }

    /**
     * Send items to the order: each goes to its station at once, or waits ("hold") until its course is fired.
     *
     * @param  list<array{id: string, product_id: string, product_uom_id: string, qty: string, modifier_option_ids?: list<string>, note?: ?string, course?: int, seat?: ?int, hold?: bool}>  $items
     */
    public function addItems(Device $device, User $user, DiningOrder $order, array $items): DiningOrder
    {
        $this->branch($device, $user, 'restaurant.serve', $order);
        $this->assertOpen($order);
        $rows = [];
        $now = now();
        foreach (array_values($items) as $it) {
            if (! is_string($it['id'] ?? null) || DiningOrderItem::whereKey($it['id'])->exists()) {
                continue; // retried send: already here
            }
            $product = Product::where('is_active', true)->find($it['product_id'] ?? null) ?? throw new DomainException('A dish is no longer on the menu. Refresh the till.', 'product_unavailable');
            $unit = ProductUom::with('uom')->where('product_id', $product->id)->where('is_sellable', true)->find($it['product_uom_id'] ?? null)
                ?? throw new DomainException("{$product->name}: that unit is not sold.", 'product_unavailable');
            $qty = Qty::toMilli((string) ($it['qty'] ?? '0'));
            if ($qty <= 0 || $qty % (10 ** (3 - $unit->uom->decimals)) !== 0) {
                throw new DomainException("{$product->name}: enter a valid quantity.");
            }
            $hold = (bool) ($it['hold'] ?? false);
            $rows[] = ['id' => $it['id'], 'dining_order_id' => $order->id, 'product_id' => $product->id, 'product_uom_id' => $unit->id, 'name' => $product->name,
                'qty' => Qty::format($qty), 'modifiers' => json_encode($this->modifiers($product, (array) ($it['modifier_option_ids'] ?? []))),
                'note' => isset($it['note']) ? mb_substr(trim((string) $it['note']), 0, 255) ?: null : null,
                'course' => max(0, min(9, (int) ($it['course'] ?? 0))), 'seat' => isset($it['seat']) && $it['seat'] !== null ? max(1, min(99, (int) $it['seat'])) : null,
                'station' => $product->station, 'status' => $hold ? 'held' : 'fired', 'added_by_name' => $user->name, 'added_at' => $now, 'fired_at' => $hold ? null : $now];
        }
        if ($rows === []) {
            return $order->load('items');
        }

        return $this->change(Actor::user($user, 'pos', $device->id), $order, 'dining_order.items_added', ['items' => count($rows)], function () use ($rows) {
            DiningOrderItem::insert($rows);
        });
    }

    /** Call a course: its held items go to the kitchen (all held items when no course is given). */
    public function fire(Device $device, User $user, DiningOrder $order, ?int $course = null): DiningOrder
    {
        $this->branch($device, $user, 'restaurant.serve', $order);
        $this->assertOpen($order);
        $q = fn () => DiningOrderItem::where('dining_order_id', $order->id)->where('status', 'held')->when($course !== null, fn ($q) => $q->where('course', $course));
        if (! $q()->exists()) {
            throw new DomainException('Nothing is waiting to be sent for that course.');
        }

        return $this->change(Actor::user($user, 'pos', $device->id), $order, 'dining_order.course_fired', ['course' => $course], function () use ($q) {
            $q()->update(['status' => 'fired', 'fired_at' => now()]);
        });
    }

    /**
     * Take items off the bill. Items still waiting can be removed by the waiter; once the kitchen has them, it needs
     * restaurant.void or a supervisor's PIN, and a reason. Paid items cannot be voided (refund them instead).
     */
    public function void(Device $device, User $user, DiningOrder $order, array $itemIds, string $reason, ?array $approval = null): DiningOrder
    {
        $branch = $this->branch($device, $user, 'restaurant.serve', $order);
        $this->assertOpen($order);
        $items = DiningOrderItem::where('dining_order_id', $order->id)->whereIn('id', $itemIds)->get();
        if ($items->isEmpty() || $items->count() !== count(array_unique($itemIds))) {
            throw new DomainException('Choose items on this bill.');
        }
        if ($items->contains(fn ($i) => $i->sale_id !== null || $i->status === 'void')) {
            throw new DomainException('Paid or already voided items cannot be voided.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Give the reason for the void.');
        }
        $by = $user->name;
        if ($items->contains(fn ($i) => $i->status !== 'held') && ! $this->authz->allows($user, 'restaurant.void', $branch)) {
            if (! $approval) {
                throw new DomainException('A supervisor must approve voiding items the kitchen already has.', 'void_needs_approval');
            }
            $by = $this->approvals->verify($device, $approval, 'restaurant.void', $branch, null, 0, $user->id)->name;
        }

        return $this->change(Actor::user($user, 'pos', $device->id), $order, 'dining_order.items_voided', ['items' => $items->count(), 'reason' => $reason], function () use ($items, $reason, $by) {
            DiningOrderItem::whereIn('id', $items->pluck('id'))->update(['status' => 'void', 'void_reason' => mb_substr($reason, 0, 255), 'voided_by_name' => $by]);
        }, $reason);
    }

    /** The waiter took the dishes to the table. */
    public function serve(Device $device, User $user, DiningOrder $order, array $itemIds): DiningOrder
    {
        $this->branch($device, $user, 'restaurant.serve', $order);

        return $this->change(Actor::user($user, 'pos', $device->id), $order, 'dining_order.items_served', ['items' => count($itemIds)], function () use ($order, $itemIds) {
            DiningOrderItem::where('dining_order_id', $order->id)->whereIn('id', $itemIds)->whereIn('status', ['fired', 'ready'])->update(['status' => 'served', 'served_at' => now()]);
        });
    }

    /** Move the bill to another (free) table. */
    public function move(Device $device, User $user, DiningOrder $order, string $tableId): DiningOrder
    {
        $branch = $this->branch($device, $user, 'restaurant.serve', $order);
        $this->assertOpen($order);
        $table = DiningTable::where('branch_id', $branch->id)->where('is_active', true)->find($tableId) ?? throw new DomainException('Choose a table on this floor plan.');
        if (DiningOrder::where('dining_table_id', $table->id)->where('status', 'open')->whereKeyNot($order->id)->exists()) {
            throw new DomainException("Table {$table->code} is taken.", 'table_busy');
        }
        $order->forceFill(['kind' => 'table', 'dining_table_id' => $table->id]);

        return $this->recorder->save(Actor::user($user, 'pos', $device->id), $order, 'dining_order.moved', branchId: $branch->id)->load('items');
    }

    /** Close a bill with nothing left to pay (everything paid or voided), freeing the table. */
    public function close(Device $device, User $user, DiningOrder $order): DiningOrder
    {
        $branch = $this->branch($device, $user, 'restaurant.serve', $order);
        $this->assertOpen($order);
        if (DiningOrderItem::where('dining_order_id', $order->id)->where('status', '<>', 'void')->whereNull('sale_id')->exists()) {
            throw new DomainException('This bill still has items to pay.', 'bill_unpaid');
        }
        $order->forceFill(['status' => DiningOrderItem::where('dining_order_id', $order->id)->whereNotNull('sale_id')->exists() ? 'closed' : 'void', 'closed_at' => now()]);

        return $this->recorder->save(Actor::user($user, 'pos', $device->id), $order, 'dining_order.closed', branchId: $branch->id)->load('items');
    }

    /** Kitchen screen: dishes ready, recalled back to cooking, or collected (served). The kitchen device acts; no sign-in. */
    public function bump(Device $kds, array $itemIds, string $to = 'ready'): int
    {
        $from = ['ready' => ['fired'], 'fired' => ['ready'], 'served' => ['fired', 'ready']][$to] ?? throw new DomainException('Unknown kitchen status.');
        if ($kds->kind !== 'kds' || ! $this->modules->isEnabled($kds->branch_id, 'restaurant')) {
            throw new AccessDenied('This device is not a kitchen screen of a restaurant branch.');
        }
        $items = DiningOrderItem::whereIn('id', $itemIds)->whereIn('status', $from)
            ->whereHas('order', fn ($q) => $q->where('branch_id', $kds->branch_id))->get();
        $actor = new Actor(id: null, name: "Kitchen screen {$kds->name}", source: 'pos', tenantId: $kds->tenant_id, deviceId: $kds->id);
        foreach ($items->groupBy('dining_order_id') as $orderId => $group) {
            $order = DiningOrder::findOrFail($orderId);
            $this->change($actor, $order, "dining_order.items_{$to}", ['items' => $group->count()], function () use ($group, $to) {
                DiningOrderItem::whereIn('id', $group->pluck('id'))->update(['status' => $to] + match ($to) {
                    'ready' => ['ready_at' => now()], 'fired' => ['ready_at' => null], default => ['served_at' => now()],
                });
            });
        }

        return $items->count();
    }

    /**
     * Modifier choices for a dish, checked against the groups the product offers.
     *
     * @return list<array{group: string, option_id: string, name: string, product_id: ?string}>
     */
    public function modifiers(Product $product, array $optionIds): array
    {
        $groups = ModifierGroup::with('options')->whereIn('id', $product->modifier_group_ids ?? [])->where('is_active', true)->get();
        $optionIds = array_values(array_unique($optionIds));
        $out = [];
        foreach ($groups as $g) {
            $chosen = $g->options->whereIn('id', $optionIds);
            if ($chosen->count() < $g->min_choices || $chosen->count() > $g->max_choices) {
                throw new DomainException("{$product->name}: choose ".($g->min_choices === $g->max_choices ? $g->min_choices : "{$g->min_choices}–{$g->max_choices}")." for {$g->name}.", 'modifiers_invalid');
            }
            foreach ($chosen as $o) {
                $out[] = ['group' => $g->name, 'option_id' => $o->id, 'name' => $o->name, 'product_id' => $o->product_id];
            }
        }
        if (count($out) !== count($optionIds)) {
            throw new DomainException("{$product->name}: a choice is not offered with this dish. Refresh the till.", 'modifiers_invalid');
        }

        return $out;
    }

    /** Orders a till or waiter tablet shows: open bills at the branch (tables, tabs, counter orders not yet collected). */
    public static function openAt(string $branchId)
    {
        return DiningOrder::with(['items', 'table'])->where('branch_id', $branchId)->where('status', 'open')->orderBy('opened_at')->get();
    }

    private function change(Actor $actor, DiningOrder $order, string $action, array $summary, callable $apply, ?string $reason = null): DiningOrder
    {
        return $this->recorder->transaction(function () use ($actor, $order, $action, $summary, $apply, $reason) {
            $order = DiningOrder::lockForUpdate()->findOrFail($order->id);
            $apply();
            $order->forceFill(['version' => $order->version + 1, 'updated_at' => now()])->save();
            $this->recorder->audit->record($actor, $action, $order, array_map(fn ($v) => [null, $v], $summary), $reason, $order->branch_id);
            $this->recorder->outbox->publish($actor, $order->fresh(), $action);

            return $order->fresh(['items', 'table']);
        });
    }

    private function assertOpen(DiningOrder $order): void
    {
        if ($order->status !== 'open') {
            throw new DomainException("{$order->number} is already closed.", 'order_closed');
        }
    }

    private function branch(Device $device, User $user, string $permission, ?DiningOrder $order = null): Branch
    {
        $branch = Branch::findOrFail($device->branch_id);
        if (! $this->modules->isEnabled($branch, 'restaurant')) {
            throw new DomainException('The Restaurant module is not on at this branch.', 'module_disabled');
        }
        if ($order && $order->branch_id !== $branch->id) {
            throw new DomainException('That bill belongs to another branch.');
        }
        if (! $this->authz->allows($user, $permission, $branch)) {
            throw new AccessDenied;
        }

        return $branch;
    }
}
