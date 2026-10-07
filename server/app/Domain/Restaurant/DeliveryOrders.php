<?php

namespace App\Domain\Restaurant;

use App\Domain\Modules\ModuleService;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\DiningOrder;
use App\Models\DiningOrderItem;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\SalePayment;
use App\Models\SalesChannel;
use App\Support\Qty;
use Illuminate\Support\Str;

/**
 * Delivery-app orders arriving by webhook (owner decision 2026-10-18: webhook hook plus manual entry at the till).
 * Each becomes a "delivery" bill whose dishes go straight to the kitchen; the till hands it to the rider and records it
 * as paid by the platform (tender "aggregator"), which pays the restaurant later.
 */
final class DeliveryOrders
{
    public function __construct(private readonly Recorder $recorder, private readonly DocumentNumbers $numbers, private readonly ModuleService $modules) {}

    /** @param array{external_id: string, number?: string, customer?: array{name?: ?string, phone?: ?string}, note?: ?string, lines: list<array{sku?: ?string, product_uom_id?: ?string, qty: string, note?: ?string}>} $in */
    public function ingest(SalesChannel $channel, array $in): DiningOrder
    {
        $platform = $channel->type;
        $ref = strtoupper(trim((string) ($in['number'] ?? $in['external_id'] ?? '')));
        if ($ref === '' || strlen($ref) > 80) {
            throw new DomainException('The order has no order number.', 'malformed');
        }
        if ($existing = DiningOrder::where('platform', $platform)->where('external_ref', $ref)->first()) {
            return $existing; // sent twice
        }
        if (! $this->modules->isEnabled($channel->branch_id, 'restaurant')) {
            throw new DomainException('The Restaurant module is not on at the channel’s branch.', 'malformed');
        }
        $now = now();
        $items = [];
        foreach (array_values($in['lines'] ?? []) as $l) {
            $unit = ! empty($l['product_uom_id']) ? ProductUom::find($l['product_uom_id'])
                : (! empty($l['sku']) ? ProductUom::where('is_base', true)->whereHas('product', fn ($q) => $q->where('sku', strtoupper((string) $l['sku'])))->first() : null);
            $unit ?? throw new DomainException('Order line not matched to a dish: '.($l['sku'] ?? $l['product_uom_id'] ?? '?').'. Use the dish’s SKU in the delivery app.', 'unmapped_line');
            $product = Product::findOrFail($unit->product_id);
            $qty = Qty::toMilli((string) ($l['qty'] ?? '0'));
            if ($qty <= 0) {
                throw new DomainException('Order quantities must be above zero.', 'malformed');
            }
            $items[] = ['product' => $product, 'unit' => $unit, 'qty' => $qty, 'note' => isset($l['note']) ? mb_substr(trim((string) $l['note']), 0, 255) ?: null : null];
        }
        if ($items === []) {
            throw new DomainException('The order has no lines.', 'malformed');
        }
        $label = SalePayment::PLATFORMS[$platform] ?? $platform;
        $actor = Actor::system($channel->tenant_id, $label, 'channel');
        $customer = trim((string) ($in['customer']['name'] ?? ''));
        $phone = trim((string) ($in['customer']['phone'] ?? ''));

        return $this->recorder->transaction(function () use ($actor, $channel, $platform, $ref, $label, $items, $now, $customer, $phone, $in) {
            $o = (new DiningOrder)->forceFill([
                'id' => (string) Str::uuid7(), 'tenant_id' => $channel->tenant_id, 'number' => $this->numbers->next($channel->tenant_id, 'dining_order'),
                'branch_id' => $channel->branch_id, 'kind' => 'delivery', 'tab_name' => "{$label} #{$ref}", 'waiter_name' => $label, 'status' => 'open',
                'note' => mb_substr(trim(implode(' · ', array_filter([$customer, $phone, (string) ($in['note'] ?? '')]))), 0, 255) ?: null,
                'opened_at' => $now, 'channel_id' => $channel->id, 'platform' => $platform, 'external_ref' => $ref,
            ]);
            $this->recorder->save($actor, $o, 'dining_order.delivery_received', branchId: $channel->branch_id);
            DiningOrderItem::insert(array_map(fn ($i) => ['id' => (string) Str::uuid7(), 'dining_order_id' => $o->id, 'product_id' => $i['product']->id,
                'product_uom_id' => $i['unit']->id, 'name' => $i['product']->name, 'qty' => Qty::format($i['qty']), 'modifiers' => null, 'note' => $i['note'],
                'course' => 0, 'seat' => null, 'station' => $i['product']->station, 'status' => 'fired', 'added_by_name' => $label, 'added_at' => $now, 'fired_at' => $now], $items));
            $this->recorder->outbox->publish($actor, $o->fresh(), 'dining_order.items_added');

            return $o->fresh('items');
        });
    }
}
