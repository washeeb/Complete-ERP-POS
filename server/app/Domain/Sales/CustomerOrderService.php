<?php

namespace App\Domain\Sales;

use App\Domain\Customers\CustomerService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\TaxCalculator;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Domain\Sync\ReviewQueue;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderLine;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Money;
use App\Support\Qty;

/**
 * Quotation → order. Prices come from the customer's price list (quantity tiers included) at the time the document
 * is saved. Confirming checks credit: within the limit it is confirmed; otherwise it goes on credit hold until someone
 * with the override right (within their limit, not the person who entered it) releases it.
 */
final class CustomerOrderService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly PriceResolver $prices,
        private readonly CustomerService $customers,
        private readonly ReviewQueue $reviews,
    ) {}

    /**
     * Create or change a quotation (status quotation) — lines replace the previous ones.
     *
     * @param array{customer_id?: string, warehouse_id?: string, valid_until?: ?string, customer_ref?: ?string, note?: ?string,
     *   lines?: list<array{product_uom_id: string, qty: string}>} $data
     */
    public function saveQuotation(Actor $actor, CustomerOrder $order, array $data): CustomerOrder
    {
        $warehouse = Warehouse::findOrFail($data['warehouse_id'] ?? $order->warehouse_id);
        $this->require($actor, 'sales.orders.manage', $warehouse);
        if ($order->exists && $order->status !== 'quotation') {
            throw new DomainException('Only a quotation can be changed. Cancel the order and quote again.');
        }
        $customer = Customer::findOrFail($data['customer_id'] ?? $order->customer_id);
        if (! $customer->is_active) {
            throw new DomainException("{$customer->name} is inactive.");
        }
        $list = $customer->price_list_id ? PriceList::findOrFail($customer->price_list_id) : PriceList::where('is_default', true)->firstOrFail();
        $lines = array_key_exists('lines', $data) ? $this->priceLines($list, $data['lines'] ?? []) : null;

        return $this->recorder->transaction(function () use ($actor, $order, $data, $warehouse, $customer, $list, $lines) {
            $isNew = ! $order->exists;
            $order->fill(array_intersect_key($data, array_flip(['valid_until', 'customer_ref', 'note'])));
            $order->forceFill(['customer_id' => $customer->id, 'warehouse_id' => $warehouse->id, 'branch_id' => $warehouse->branch_id, 'price_list_id' => $list->id,
                'currency_code' => $list->currency_code, 'prices_include_tax' => $list->prices_include_tax]);
            if ($isNew) {
                $order->forceFill(['tenant_id' => $actor->tenantId, 'quote_number' => $this->numbers->next($actor->tenantId, 'quotation'), 'status' => 'quotation',
                    'created_by' => $actor->id, 'created_by_name' => $actor->name, 'valid_until' => $data['valid_until'] ?? now()->addDays(14)->toDateString()]);
            }
            if ($lines !== null) {
                $order->forceFill(['net_minor' => array_sum(array_column($lines, 'net_minor')), 'tax_minor' => array_sum(array_column($lines, 'tax_minor')),
                    'total_minor' => array_sum(array_column($lines, 'total_minor'))]);
            }
            $this->recorder->save($actor, $order, $isNew ? 'quotation.created' : 'quotation.updated', branchId: $order->branch_id);
            if ($lines !== null) {
                CustomerOrderLine::where('customer_order_id', $order->id)->delete();
                foreach ($lines as $l) {
                    CustomerOrderLine::create(['customer_order_id' => $order->id] + $l);
                }
                $this->recorder->audit->record($actor, 'quotation.lines_changed', $order, ['lines' => [null, count($lines)], 'total_minor' => [null, $order->total_minor]], null, $order->branch_id);
                $this->recorder->outbox->publish($actor, $order->fresh(), 'quotation.lines_changed');
            }

            return $order->fresh('lines');
        });
    }

    /** Quotation → order. Credit customers are checked; cash customers (no credit account) are confirmed as pay-on-delivery. */
    public function confirm(Actor $actor, CustomerOrder $order): CustomerOrder
    {
        $this->require($actor, 'sales.orders.manage', $order->warehouseModel());
        if ($order->status !== 'quotation') {
            throw new DomainException("{$this->label($order)} is already ".(CustomerOrder::STATUSES[$order->status] ?? $order->status).'.');
        }
        if ($order->lines()->count() === 0) {
            throw new DomainException('Add at least one line.');
        }
        $customer = Customer::findOrFail($order->customer_id);
        $problems = $customer->credit_limit_minor === null && ! $customer->credit_hold ? [] : $this->customers->creditProblems($customer, $order->total_minor, $order->id);

        return $this->recorder->transaction(function () use ($actor, $order, $customer, $problems) {
            $order->forceFill(['number' => $this->numbers->next($actor->tenantId, 'customer_order'), 'confirmed_at' => now(),
                'status' => $problems ? 'on_hold' : 'confirmed', 'hold_reason' => $problems ? mb_substr(ucfirst(implode('; ', $problems)), 0, 255) : null]);
            $this->recorder->save($actor, $order, $problems ? 'customer_order.held' : 'customer_order.confirmed', branchId: $order->branch_id);
            if ($problems) {
                $this->reviews->add($actor, 'credit_hold', 'customer_order', $order->id,
                    "Order {$order->number} for {$customer->name} (".Money::display($order->total_minor, $order->currency_code).') is on credit hold: '.implode('; ', $problems),
                    [], $order->branch_id);
            }

            return $order;
        });
    }

    /** Release a credit hold: needs the override right, a limit covering the order, and not the person who entered it. */
    public function release(Actor $actor, CustomerOrder $order, string $reason): CustomerOrder
    {
        $this->require($actor, 'customers.credit.override', $order->warehouseModel());
        if ($order->status !== 'on_hold') {
            throw new DomainException("{$this->label($order)} is not on credit hold.");
        }
        if ($order->created_by && $order->created_by === $actor->id) {
            throw new DomainException('You entered this order, so someone else must release it.', 'own_document');
        }
        $excess = $this->customers->overrideAmount(Customer::findOrFail($order->customer_id), $order->total_minor, $order->id);
        if (! $this->authz->allowsAmount($actor->user, 'customers.credit.override', $order->warehouseModel(), 'credit_override_minor', $excess)) {
            throw new DomainException('This order is above your credit-override limit. Someone with a higher limit must release it.', 'over_limit');
        }
        if (trim($reason) === '') {
            throw new DomainException('Give a reason (e.g. “payment promised Friday”).');
        }

        return $this->recorder->transaction(function () use ($actor, $order, $reason) {
            $order->forceFill(['status' => 'confirmed', 'released_by_name' => $actor->name, 'released_at' => now()]);
            $this->recorder->save($actor, $order, 'customer_order.released', $reason, $order->branch_id);
            $this->reviews->closeBySystem('customer_order', $order->id, "Released by {$actor->name}: {$reason}");

            return $order;
        });
    }

    public function cancel(Actor $actor, CustomerOrder $order, string $reason): CustomerOrder
    {
        $this->require($actor, 'sales.orders.manage', $order->warehouseModel());
        if (! in_array($order->status, ['quotation', 'on_hold', 'confirmed', 'partially_delivered'], true)) {
            throw new DomainException("{$this->label($order)} cannot be cancelled now.");
        }
        if (trim($reason) === '') {
            throw new DomainException('Give a reason.');
        }
        $status = $order->status === 'quotation' ? 'lost' : 'cancelled'; // a partly delivered order closes; what was delivered stays invoiced
        $order->forceFill(['status' => $status, 'closed_at' => now(), 'close_reason' => mb_substr(trim($reason), 0, 255)]);
        $this->reviews->closeBySystem('customer_order', $order->id, 'Order cancelled');

        return $this->recorder->save($actor, $order, $status === 'lost' ? 'quotation.lost' : 'customer_order.cancelled', $reason, $order->branch_id);
    }

    /** @return list<array<string, mixed>> */
    private function priceLines(PriceList $list, array $rows): array
    {
        $out = [];
        foreach (array_values($rows) as $i => $r) {
            $unit = ProductUom::with('uom')->find($r['product_uom_id'] ?? null) ?? throw new DomainException('Choose a product and unit on every line.');
            $product = Product::with('taxGroup.rates')->where('is_active', true)->findOrFail($unit->product_id);
            $qty = Qty::toMilli((string) ($r['qty'] ?? '0'));
            if ($qty <= 0) {
                throw new DomainException("{$product->name}: enter a quantity above zero.");
            }
            $price = $this->prices->resolve($list, $product->id, $unit->id, $qty, now())?->amount_minor
                ?? throw new DomainException("{$product->name} ({$unit->uom->code}) has no {$list->name} price.");
            $lineTotal = Qty::divRound($qty * $price, 1000);
            $rates = $product->taxGroup?->rates->where('is_active', true)->map(fn ($x) => ['code' => $x->code, 'name' => $x->name, 'rate_bp' => $x->rate_bp])->values()->all() ?? [];
            $t = TaxCalculator::line($lineTotal, $rates, $list->prices_include_tax);
            $out[] = ['line_no' => $i + 1, 'product_id' => $product->id, 'product_uom_id' => $unit->id, 'sku' => $product->sku, 'name' => $product->name,
                'uom_code' => $unit->uom->code, 'factor' => $unit->factor, 'qty' => Qty::format($qty), 'delivered_qty' => '0.000', 'unit_price_minor' => $price,
                'net_minor' => $t['net'], 'tax_minor' => $t['tax'], 'total_minor' => $t['gross'], 'tax_breakdown' => $t['breakdown']];
        }
        if ($out === []) {
            throw new DomainException('Add at least one line.');
        }

        return $out;
    }

    private function label(CustomerOrder $o): string
    {
        return $o->number ?? $o->quote_number;
    }

    private function require(Actor $actor, string $permission, Warehouse $warehouse): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, $permission, $warehouse)) {
            throw new AccessDenied;
        }
    }
}
