<?php

namespace App\Domain\Sales;

use App\Domain\Customers\CustomerLedger;
use App\Domain\Inventory\StockLedger;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\SalePayment;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Security\Authorizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Money in and corrections on customer accounts. A payment settles the oldest open items first (or the ones
 * chosen); anything left over stays on the account as credit. A credit note cancels an invoice (or what is still
 * owed on it), optionally taking the goods back into stock; invoices themselves never change.
 */
final class ReceivablesService
{
    public const METHODS = ['cash' => 'Cash', 'momo' => 'Mobile money', 'bank' => 'Bank transfer', 'cheque' => 'Cheque', 'card' => 'Card'];

    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly CustomerLedger $ledger,
        private readonly StockLedger $stock,
    ) {}

    /** @param  list<string>|null  $invoiceIds  settle only these documents (else oldest first) */
    public function recordPayment(Actor $actor, Customer $customer, Branch $branch, string $method, int $amountMinor, ?string $reference = null,
        ?string $receivedOn = null, ?array $invoiceIds = null, ?string $provider = null, ?string $note = null): CustomerPayment
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'receivables.collect', $branch)) {
            throw new AccessDenied;
        }
        if (! array_key_exists($method, self::METHODS)) {
            throw new DomainException('Choose how the customer paid.');
        }
        if ($amountMinor <= 0) {
            throw new DomainException('Enter an amount above zero.');
        }
        if ($method === 'momo' && ! array_key_exists((string) $provider, SalePayment::MOMO_PROVIDERS)) {
            throw new DomainException('Choose the mobile money network.');
        }
        if (in_array($method, ['momo', 'bank', 'cheque'], true) && trim((string) $reference) === '') {
            throw new DomainException('Enter the transaction or cheque reference.');
        }
        $day = Carbon::parse($receivedOn ?? now()->toDateString());
        if ($day->isAfter(now()->endOfDay())) {
            throw new DomainException('A payment cannot be dated in the future.');
        }
        $currency = Tenant::find($customer->tenant_id)->currency_code;

        return $this->recorder->transaction(function () use ($actor, $customer, $branch, $method, $amountMinor, $reference, $day, $invoiceIds, $provider, $note, $currency) {
            DB::table('customer_balances')->where('customer_id', $customer->id)->lockForUpdate()->first(); // one settlement at a time per customer
            $p = new CustomerPayment;
            $p->forceFill(['tenant_id' => $customer->tenant_id, 'number' => $this->numbers->next($customer->tenant_id, 'customer_payment'),
                'customer_id' => $customer->id, 'branch_id' => $branch->id, 'method' => $method, 'provider' => $method === 'momo' ? $provider : null,
                'reference' => $reference ? mb_substr(trim($reference), 0, 80) : null, 'currency_code' => $currency, 'amount_minor' => $amountMinor,
                'received_on' => $day->toDateString(), 'received_by_name' => $actor->name, 'note' => $note]);
            $this->recorder->save($actor, $p, 'customer_payment.received', branchId: $branch->id);
            $plan = $this->ledger->plan($customer->id, $amountMinor, $invoiceIds);
            $this->ledger->post($actor, $customer, 'payment', -$amountMinor, $currency, 'customer_payment', $p->id, $p->number, null, $branch->id,
                $day->copy()->setTimeFrom(now()), $plan);

            return $p;
        });
    }

    /**
     * Credit an invoice in full. What is still owed on it is cleared; if it was already paid, the amount stays on the
     * account as credit (to use against later invoices, or to refund). Goods can come back into a warehouse.
     */
    public function creditNote(Actor $actor, Invoice $invoice, string $reason, ?Warehouse $returnTo = null): CreditNote
    {
        $branch = Branch::findOrFail($invoice->branch_id);
        if (! $actor->user || ! $this->authz->allows($actor->user, 'receivables.credit_note', $branch)) {
            throw new AccessDenied;
        }
        if ($invoice->status !== 'posted') {
            throw new DomainException("Invoice {$invoice->number} is already credited.");
        }
        if (trim($reason) === '') {
            throw new DomainException('Give the reason for the credit note.');
        }
        $customer = Customer::findOrFail($invoice->customer_id);

        return $this->recorder->transaction(function () use ($actor, $invoice, $reason, $returnTo, $branch, $customer) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            $note = new CreditNote;
            $note->forceFill(['tenant_id' => $invoice->tenant_id, 'number' => $this->numbers->next($invoice->tenant_id, 'credit_note'), 'invoice_id' => $invoice->id,
                'customer_id' => $customer->id, 'branch_id' => $branch->id, 'warehouse_id' => $returnTo?->id, 'currency_code' => $invoice->currency_code,
                'net_minor' => $invoice->net_minor, 'tax_minor' => $invoice->tax_minor, 'total_minor' => $invoice->total_minor,
                'reason' => mb_substr(trim($reason), 0, 255), 'goods_returned' => $returnTo !== null, 'approved_by_name' => $actor->name]);
            $this->recorder->save($actor, $note, 'credit_note.issued', $reason, $branch->id);

            $open = (int) collect($this->ledger->openItems($customer->id))->firstWhere('doc_id', $invoice->id)?->open_minor;
            $this->ledger->post($actor, $customer, 'credit_note', -$invoice->total_minor, $invoice->currency_code, 'credit_note', $note->id, $note->number,
                null, $branch->id, null, $open > 0 ? [['to_type' => 'invoice', 'to_id' => $invoice->id, 'amount_minor' => $open]] : []);

            if ($returnTo) {
                $entries = $invoice->lines()->get()->map(fn ($l) => ['product' => Product::findOrFail($l->product_id),
                    'unit' => ProductUom::findOrFail($l->product_uom_id), 'qty_milli' => (int) round((float) $l->qty * 1000)])->all();
                $this->stock->post($actor, $returnTo, 'customer_return', $entries, "Return on {$note->number} ({$invoice->number})", 'credit_note', $note->id);
            }
            $invoice->status = 'credited';
            $this->recorder->save($actor, $invoice, 'invoice.credited', $reason, $branch->id);

            return $note;
        });
    }

    /** Open amount per document id (invoices and till credit sales) — for screens. */
    public function openAmounts(string $customerId): array
    {
        return collect($this->ledger->openItems($customerId))->mapWithKeys(fn ($i) => [$i->doc_id => (int) $i->open_minor])->all();
    }
}
