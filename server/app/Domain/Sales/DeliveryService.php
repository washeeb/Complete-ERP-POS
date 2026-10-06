<?php

namespace App\Domain\Sales;

use App\Domain\Customers\CustomerLedger;
use App\Domain\Inventory\StockLedger;
use App\Domain\Pricing\TaxCalculator;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderLine;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\ProductUom;
use App\Security\Authorizer;
use App\Support\Qty;

/**
 * Delivery notes. Posting one takes the goods out of stock and — owner decision — raises the invoice for exactly what
 * was delivered, due after the customer's payment terms, and puts it on the customer's account. Partial deliveries
 * are fine; each raises its own invoice. Nothing is delivered on an order that is on credit hold.
 */
final class DeliveryService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly StockLedger $stock,
        private readonly CustomerLedger $receivables,
    ) {}

    /**
     * @param  array<string, string>|null  $quantities  order line id => qty to deliver now (in the line unit); null = everything outstanding
     * @return array{delivery: Delivery, invoice: Invoice}
     */
    public function deliver(Actor $actor, CustomerOrder $order, ?array $quantities = null, array $info = []): array
    {
        $warehouse = $order->warehouseModel();
        if (! $actor->user || ! $this->authz->allows($actor->user, 'sales.deliver', $warehouse)) {
            throw new AccessDenied;
        }
        if ($order->status === 'on_hold') {
            throw new DomainException("Order {$order->number} is on credit hold. A manager must release it before anything is delivered.", 'credit_hold');
        }
        if (! $order->isDeliverable()) {
            throw new DomainException("Order {$order->number} cannot be delivered (".(CustomerOrder::STATUSES[$order->status] ?? $order->status).').', 'wrong_status');
        }

        return $this->recorder->transaction(function () use ($actor, $order, $quantities, $info, $warehouse) {
            $order = CustomerOrder::lockForUpdate()->findOrFail($order->id);
            $customer = Customer::findOrFail($order->customer_id);
            $now = now();
            $picked = [];
            foreach ($order->lines()->get() as $l) {
                $open = Qty::toMilli((string) $l->qty) - Qty::toMilli((string) $l->delivered_qty);
                $want = $quantities === null ? $open : Qty::toMilli((string) ($quantities[$l->id] ?? '0'));
                if ($want < 0) {
                    throw new DomainException('Quantities cannot be negative.');
                }
                if ($want > $open) {
                    throw new DomainException("{$l->name}: only ".Qty::format($open)." {$l->uom_code} left to deliver on this order.");
                }
                if ($want > 0) {
                    $picked[] = [$l, $want];
                }
            }
            if ($picked === []) {
                throw new DomainException('Enter what is being delivered.');
            }

            $delivery = new Delivery;
            $delivery->forceFill(['tenant_id' => $order->tenant_id, 'number' => $this->numbers->next($order->tenant_id, 'delivery'),
                'customer_order_id' => $order->id, 'customer_id' => $customer->id, 'branch_id' => $order->branch_id, 'warehouse_id' => $order->warehouse_id,
                'status' => 'posted', 'delivered_to' => $info['delivered_to'] ?? null, 'vehicle' => $info['vehicle'] ?? null, 'note' => $info['note'] ?? null,
                'delivered_by_name' => $actor->name, 'delivered_at' => $now])->save();

            $entries = [];
            $invoiceLines = [];
            foreach ($picked as $i => [$l, $qty]) {
                DeliveryLine::create(['delivery_id' => $delivery->id, 'line_no' => $i + 1, 'customer_order_line_id' => $l->id, 'product_id' => $l->product_id,
                    'product_uom_id' => $l->product_uom_id, 'sku' => $l->sku, 'name' => $l->name, 'uom_code' => $l->uom_code, 'factor' => $l->factor, 'qty' => Qty::format($qty)]);
                $entries[] = ['product' => Product::findOrFail($l->product_id), 'unit' => ProductUom::findOrFail($l->product_uom_id), 'qty_milli' => -$qty];
                $l->delivered_qty = Qty::format(Qty::toMilli((string) $l->delivered_qty) + $qty);
                $l->save();
                $invoiceLines[] = $this->invoiceLine($l, $qty, $order->prices_include_tax);
            }
            $this->stock->post($actor, $warehouse, 'delivery', $entries, "Delivery {$delivery->number} to {$customer->name}", 'delivery', $delivery->id);

            $invoice = $this->invoice($actor, $order, $customer, $delivery, $invoiceLines);

            $order->status = $order->lines()->get()->every(fn (CustomerOrderLine $x) => Qty::toMilli((string) $x->delivered_qty) >= Qty::toMilli((string) $x->qty))
                ? 'delivered' : 'partially_delivered';
            $this->recorder->save($actor, $order->load('lines'), 'customer_order.delivered', branchId: $order->branch_id);
            $this->recorder->audit->record($actor, 'delivery.posted', $delivery, ['invoice' => [null, $invoice->number], 'lines' => [null, count($picked)]], null, $order->branch_id);
            $this->recorder->outbox->publish($actor, $delivery->load('lines'), 'delivery.posted');

            return ['delivery' => $delivery, 'invoice' => $invoice];
        });
    }

    private function invoiceLine(CustomerOrderLine $l, int $qty, bool $inclusive): array
    {
        $lineTotal = Qty::divRound($qty * $l->unit_price_minor, 1000);
        $rates = array_map(fn ($b) => ['code' => $b['code'], 'name' => $b['name'], 'rate_bp' => $b['rate_bp']], $l->tax_breakdown ?? []);
        $t = TaxCalculator::line($lineTotal, $rates, $inclusive); // the order's tax rates (as agreed), on the quantity delivered

        return ['product_id' => $l->product_id, 'product_uom_id' => $l->product_uom_id, 'sku' => $l->sku, 'name' => $l->name, 'uom_code' => $l->uom_code,
            'factor' => $l->factor, 'qty' => Qty::format($qty), 'unit_price_minor' => $l->unit_price_minor,
            'net_minor' => $t['net'], 'tax_minor' => $t['tax'], 'total_minor' => $t['gross'], 'tax_breakdown' => $t['breakdown']];
    }

    private function invoice(Actor $actor, CustomerOrder $order, Customer $customer, Delivery $delivery, array $lines): Invoice
    {
        $today = now()->startOfDay();
        $invoice = new Invoice;
        $invoice->forceFill(['tenant_id' => $order->tenant_id, 'number' => $this->numbers->next($order->tenant_id, 'invoice'), 'customer_id' => $customer->id,
            'branch_id' => $order->branch_id, 'customer_order_id' => $order->id, 'delivery_id' => $delivery->id, 'status' => 'posted',
            'currency_code' => $order->currency_code, 'prices_include_tax' => $order->prices_include_tax,
            'net_minor' => array_sum(array_column($lines, 'net_minor')), 'tax_minor' => array_sum(array_column($lines, 'tax_minor')),
            'total_minor' => array_sum(array_column($lines, 'total_minor')), 'tax_breakdown' => TaxCalculator::sum(array_column($lines, 'tax_breakdown')),
            'issued_on' => $today->toDateString(), 'due_on' => $today->copy()->addDays((int) $customer->payment_terms_days)->toDateString(),
            'customer_name' => $customer->name, 'customer_tax_no' => $customer->tax_no, 'customer_ref' => $order->customer_ref])->save();
        foreach ($lines as $i => $l) {
            InvoiceLine::create(['invoice_id' => $invoice->id, 'line_no' => $i + 1] + $l);
        }
        $this->receivables->post($actor, $customer, 'invoice', $invoice->total_minor, $invoice->currency_code, 'invoice', $invoice->id, $invoice->number,
            $invoice->due_on, $invoice->branch_id);
        $this->receivables->applyCredits($actor, $customer); // money already on the account pays it straight away
        $this->recorder->audit->record($actor, 'invoice.posted', $invoice, ['total_minor' => [null, $invoice->total_minor], 'due_on' => [null, $invoice->due_on->toDateString()]],
            null, $invoice->branch_id);
        $this->recorder->outbox->publish($actor, $invoice->load('lines'), 'invoice.posted');

        return $invoice;
    }
}
