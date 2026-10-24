<?php

namespace App\Domain\Channels;

use App\Domain\Inventory\StockAvailability;
use App\Domain\Inventory\StockLedger;
use App\Domain\Pricing\TaxCalculator;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Domain\Sync\ReviewQueue;
use App\Models\Branch;
use App\Models\ChannelProduct;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\SalesChannel;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Qty;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Online orders: accepted once per (channel, external id), stock reserved at once, issued from the
 * ledger only when fulfilled, released when cancelled. The store owns what the customer was charged;
 * the ERP records it, recomputes tax, and raises a review item if the store's total disagrees.
 */
final class OnlineOrderService
{
    public const PAYMENT_METHODS = ['cash' => 'Cash', 'momo' => 'Mobile money', 'card' => 'Card', 'online' => 'Paid online'];

    public function __construct(
        private readonly Recorder $recorder,
        private readonly StockAvailability $availability,
        private readonly StockLedger $ledger,
        private readonly ReviewQueue $reviews,
        private readonly Authorizer $authz,
    ) {}

    /**
     * @param  array{external_id: string, number?: string, placed_at?: string, customer: array{name: string, phone?: ?string, email?: ?string},
     *   fulfilment?: string, address?: ?array, payment_status?: string, payment_method?: ?string, payment_reference?: ?string, note?: ?string,
     *   total_minor?: ?int, lines: list<array{qty: string, unit_price_minor: int, product_uom_id?: string, sku?: string, external_id?: string, external_variant_id?: string}>}  $in
     * @return array{order: SalesOrder, created: bool}
     */
    public function ingest(SalesChannel $channel, array $in): array
    {
        $existing = SalesOrder::where('channel_id', $channel->id)->where('external_id', (string) $in['external_id'])->first();
        if ($existing) {
            return ['order' => $existing, 'created' => false]; // webhook retried / order re-sent
        }
        if (! $channel->isActive()) {
            throw new DomainException("{$channel->name} is not active; its orders are not accepted.", 'channel_inactive');
        }
        $list = $channel->priceList;
        $actor = Actor::system($channel->tenant_id, "Online channel {$channel->name}", 'channel');

        $lines = [];
        $breakdowns = [];
        $net = $tax = $total = 0;
        $wanted = [];
        foreach (array_values($in['lines'] ?? []) as $i => $l) {
            $unit = $this->resolveUnit($channel, $l)
                ?? throw new DomainException('Order line not matched to a product: '.($l['sku'] ?? $l['external_id'] ?? '?').'. Map it under the channel’s products.', 'unmapped_line');
            $product = Product::with('taxGroup.rates')->findOrFail($unit->product_id);
            $qty = Qty::toMilli((string) $l['qty']);
            if ($qty <= 0) {
                throw new DomainException('Order quantities must be above zero.', 'malformed');
            }
            $lineTotal = Qty::divRound($qty * (int) $l['unit_price_minor'], 1000);
            $rates = $product->taxGroup->rates->where('is_active', true)->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all();
            $t = TaxCalculator::line($lineTotal, $rates, $list->prices_include_tax);
            $lines[] = compact('product', 'unit', 'qty', 't') + ['price' => (int) $l['unit_price_minor'], 'no' => $i + 1];
            $breakdowns[] = $t['breakdown'];
            $net += $t['net'];
            $tax += $t['tax'];
            $total += $t['gross'];
            $wanted[$product->id] = ($wanted[$product->id] ?? 0) + Qty::toBaseMilli($qty, Qty::factorMicro($unit->factor));
        }
        if ($lines === []) {
            throw new DomainException('The order has no lines.', 'malformed');
        }

        return $this->recorder->transaction(function () use ($channel, $in, $actor, $lines, $breakdowns, $net, $tax, $total, $wanted, $list) {
            $order = new SalesOrder;
            $order->forceFill([
                'id' => (string) Str::uuid7(), 'tenant_id' => $channel->tenant_id, 'channel_id' => $channel->id,
                'branch_id' => $channel->branch_id, 'warehouse_id' => $channel->warehouse_id,
                'external_id' => (string) $in['external_id'], 'number' => mb_substr((string) ($in['number'] ?? $in['external_id']), 0, 40),
                'status' => 'confirmed', 'short_stock' => false, 'payment_status' => $in['payment_status'] ?? 'unpaid', 'fulfilment' => $in['fulfilment'] ?? 'pickup',
                'customer_name' => mb_substr((string) ($in['customer']['name'] ?? 'Online customer'), 0, 255),
                'customer_phone' => $in['customer']['phone'] ?? null, 'customer_email' => $in['customer']['email'] ?? null,
                'delivery_address' => $in['address'] ?? null, 'currency_code' => $list->currency_code, 'prices_include_tax' => $list->prices_include_tax,
                'net_minor' => $net, 'tax_minor' => $tax, 'total_minor' => $total, 'tax_breakdown' => TaxCalculator::sum($breakdowns),
                'payment_method' => $in['payment_method'] ?? null, 'payment_reference' => $in['payment_reference'] ?? null,
                'note' => $in['note'] ?? null, 'placed_at' => isset($in['placed_at']) ? Carbon::parse($in['placed_at']) : now(),
            ]);
            $order->save();
            foreach ($lines as $l) {
                SalesOrderLine::create([
                    'sales_order_id' => $order->id, 'line_no' => $l['no'], 'product_id' => $l['product']->id, 'product_uom_id' => $l['unit']->id,
                    'sku' => $l['product']->sku, 'name' => $l['product']->name, 'uom_code' => $l['unit']->uom->code, 'factor' => $l['unit']->factor,
                    'qty' => Qty::format($l['qty']), 'unit_price_minor' => $l['price'], 'line_total_minor' => $l['t']['gross'],
                    'net_minor' => $l['t']['net'], 'tax_minor' => $l['t']['tax'], 'tax_breakdown' => $l['t']['breakdown'],
                ]);
            }

            $short = $this->availability->reserve($actor, $channel->warehouse_id, $wanted, Qty::toMilli((string) $channel->safety_stock), 'sales_order', $order->id);
            if ($short) {
                $order->short_stock = true;
                $order->save();
                $names = Product::whereIn('id', array_keys($short))->pluck('name')->implode(', ');
                $this->reviews->add($actor, 'oversell', 'sales_order', $order->id,
                    "Online order {$order->number} could not be fully covered from stock ({$names}) — contact the customer or source the goods",
                    ['short' => array_map(fn ($m) => Qty::format($m), $short)], $channel->branch_id);
            }
            if (isset($in['total_minor']) && (int) $in['total_minor'] !== $total) {
                $this->reviews->add($actor, 'channel_total', 'sales_order', $order->id,
                    "Online order {$order->number}: the store charged ".($in['total_minor'] / 100).' but the items add up to '.($total / 100).' (shipping, discount or price difference)',
                    ['store_total_minor' => (int) $in['total_minor'], 'items_total_minor' => $total], $channel->branch_id);
            }

            $this->recorder->audit->record($actor, 'sales_order.received', $order, ['total_minor' => [null, $total], 'lines' => [null, count($lines)]],
                null, $channel->branch_id);
            $this->recorder->outbox->publish($actor, $order->load('lines'), 'sales_order.received', ['channel_id' => $channel->id]);

            return ['order' => $order, 'created' => true];
        });
    }

    public function markReady(Actor $actor, SalesOrder $order): SalesOrder
    {
        $this->require($actor, $order);
        if ($order->status !== 'confirmed') {
            throw new DomainException('Only a confirmed order can be marked ready.');
        }
        $order->status = 'ready';
        $order = $this->recorder->save($actor, $order, 'sales_order.ready', null, $order->branch_id);
        if (SalesChannel::whereKey($order->channel_id)->where('type', 'whatsapp')->exists()) {
            DB::afterCommit(fn () => app(WhatsappOrders::class)->tellReady($order)); // the customer is told on WhatsApp
        }

        return $order;
    }

    /** Goods handed over / shipped: reservation consumed, stock issued from the ledger, payment recorded. */
    public function fulfil(Actor $actor, SalesOrder $order, ?string $paymentMethod = null, ?string $paymentReference = null): SalesOrder
    {
        $this->require($actor, $order);
        if (! in_array($order->status, ['confirmed', 'ready'], true)) {
            throw new DomainException('This order is not open.');
        }
        if ($order->payment_status !== 'paid') {
            if (! array_key_exists((string) $paymentMethod, self::PAYMENT_METHODS)) {
                throw new DomainException('Record how the customer paid before handing over the goods.');
            }
            if ($paymentMethod === 'momo' && strlen(trim((string) $paymentReference)) < 4) {
                throw new DomainException('Enter the mobile money transaction ID.');
            }
        }

        return $this->recorder->transaction(function () use ($actor, $order, $paymentMethod, $paymentReference) {
            $this->availability->settle('sales_order', $order->id, 'consumed');
            $warehouse = Warehouse::findOrFail($order->warehouse_id);
            $this->ledger->post($actor, $warehouse, 'sale', $order->lines->map(fn (SalesOrderLine $l) => [
                'product' => $l->product, 'unit' => $l->productUom, 'qty_milli' => -Qty::toMilli((string) $l->qty),
            ])->all(), "Online order {$order->number}", 'sales_order', $order->id);
            if ($order->payment_status !== 'paid') {
                $order->payment_status = 'paid';
                $order->payment_method = $paymentMethod;
                $order->payment_reference = $paymentReference ? trim($paymentReference) : null;
            }
            $order->forceFill(['status' => 'fulfilled', 'fulfilled_at' => now(), 'fulfilled_by_name' => $actor->name]);

            return $this->recorder->save($actor, $order, 'sales_order.fulfilled', null, $order->branch_id);
        });
    }

    public function cancel(Actor $actor, SalesOrder $order, string $reason): SalesOrder
    {
        $this->require($actor, $order);
        if (! $order->isOpen()) {
            throw new DomainException('Only an open order can be cancelled.');
        }
        if (trim($reason) === '') {
            throw new DomainException('Give a reason for cancelling.');
        }

        return $this->recorder->transaction(function () use ($actor, $order, $reason) {
            $this->availability->settle('sales_order', $order->id, 'released');
            $order->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => mb_substr($reason, 0, 255)]);

            return $this->recorder->save($actor, $order, 'sales_order.cancelled', $reason, $order->branch_id);
        });
    }

    private function resolveUnit(SalesChannel $channel, array $l): ?ProductUom
    {
        if (! empty($l['product_uom_id'])) {
            return ProductUom::with('uom')->where('is_sellable', true)->find($l['product_uom_id']);
        }
        $ext = $l['external_variant_id'] ?? $l['external_id'] ?? null;
        if ($ext) {
            $map = ChannelProduct::where('channel_id', $channel->id)
                ->where(fn ($q) => $q->where('external_variant_id', (string) $ext)->orWhere('external_id', (string) $ext))->first();
            if ($map) {
                return ProductUom::with('uom')->find($map->product_uom_id);
            }
        }
        if (! empty($l['sku'])) {
            $product = Product::where('sku', strtoupper(trim($l['sku'])))->first();

            return $product ? ProductUom::with('uom')->where('product_id', $product->id)->where('is_base', true)->first() : null;
        }

        return null;
    }

    private function require(Actor $actor, SalesOrder $order): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'orders.manage', Branch::find($order->branch_id))) {
            throw new AccessDenied;
        }
    }
}
