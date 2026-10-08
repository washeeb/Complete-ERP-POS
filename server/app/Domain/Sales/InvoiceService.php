<?php

namespace App\Domain\Sales;

use App\Domain\Customers\CustomerLedger;
use App\Domain\Pricing\TaxCalculator;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\Recorder;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;

/**
 * Raising a customer invoice: numbered, due after the customer's payment terms, put on their account (money already on
 * the account pays it straight away), audited and published. Used by deliveries and by recurring membership billing.
 */
final class InvoiceService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly DocumentNumbers $numbers,
        private readonly CustomerLedger $receivables,
    ) {}

    /**
     * @param  list<array{product_id: string, product_uom_id: string, sku: string, name: string, uom_code: string, factor: string, qty: string, unit_price_minor: int, net_minor: int, tax_minor: int, total_minor: int, tax_breakdown: array}>  $lines
     * @param  array{branch_id: string, currency_code: string, prices_include_tax: bool, customer_order_id?: ?string, delivery_id?: ?string, customer_ref?: ?string, membership_id?: ?string}  $meta
     */
    public function raise(Actor $actor, Customer $customer, array $lines, array $meta): Invoice
    {
        return $this->recorder->transaction(function () use ($actor, $customer, $lines, $meta) {
            $today = now()->startOfDay();
            $invoice = new Invoice;
            $invoice->forceFill(['tenant_id' => $customer->tenant_id, 'number' => $this->numbers->next($customer->tenant_id, 'invoice'), 'customer_id' => $customer->id,
                'branch_id' => $meta['branch_id'], 'customer_order_id' => $meta['customer_order_id'] ?? null, 'delivery_id' => $meta['delivery_id'] ?? null,
                'membership_id' => $meta['membership_id'] ?? null, 'status' => 'posted',
                'currency_code' => $meta['currency_code'], 'prices_include_tax' => $meta['prices_include_tax'],
                'net_minor' => array_sum(array_column($lines, 'net_minor')), 'tax_minor' => array_sum(array_column($lines, 'tax_minor')),
                'total_minor' => array_sum(array_column($lines, 'total_minor')), 'tax_breakdown' => TaxCalculator::sum(array_column($lines, 'tax_breakdown')),
                'issued_on' => $today->toDateString(), 'due_on' => $today->copy()->addDays((int) $customer->payment_terms_days)->toDateString(),
                'customer_name' => $customer->name, 'customer_tax_no' => $customer->tax_no, 'customer_ref' => $meta['customer_ref'] ?? null])->save();
            foreach ($lines as $i => $l) {
                InvoiceLine::create(['invoice_id' => $invoice->id, 'line_no' => $i + 1] + $l);
            }
            $this->receivables->post($actor, $customer, 'invoice', $invoice->total_minor, $invoice->currency_code, 'invoice', $invoice->id, $invoice->number,
                $invoice->due_on, $invoice->branch_id);
            $this->receivables->applyCredits($actor, $customer);
            $this->recorder->audit->record($actor, 'invoice.posted', $invoice, ['total_minor' => [null, $invoice->total_minor], 'due_on' => [null, $invoice->due_on->toDateString()]],
                null, $invoice->branch_id);
            $this->recorder->outbox->publish($actor, $invoice->load('lines'), 'invoice.posted');

            return $invoice;
        });
    }
}
