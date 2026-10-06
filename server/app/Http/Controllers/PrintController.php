<?php

namespace App\Http\Controllers;

use App\Domain\Customers\CustomerLedger;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\LegalEntity;
use App\Models\Tenant;
use App\Security\Authorizer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** A4 documents for printing or "Save as PDF" in the browser (invoice, quotation/order, statement, payment receipt). */
class PrintController extends Controller
{
    public function __construct(private readonly Authorizer $authz) {}

    private function allow(Request $request, array $permissions, ?string $branchId, string $tenantId): void
    {
        $user = $request->user();
        abort_unless($user && $user->tenant_id === $tenantId, 404); // never another company's documents
        $target = $branchId ? Branch::find($branchId) : null;
        foreach ($permissions as $p) {
            if ($user && ($target ? $this->authz->allows($user, $p, $target) : $this->authz->allowsAnywhere($user, $p))) {
                return;
            }
        }
        abort(403);
    }

    private function company(?string $branchId): array
    {
        $tenant = Tenant::find(auth()->user()->tenant_id);
        $branch = $branchId ? Branch::find($branchId) : Branch::where('is_hq', true)->first();
        $entity = $branch?->legal_entity_id ? LegalEntity::find($branch->legal_entity_id) : LegalEntity::first();

        return ['name' => $entity->name ?? $tenant->name, 'tin' => $entity->tin ?? null, 'vat_no' => $entity->vat_no ?? null,
            'address' => $branch?->address, 'phone' => $branch?->phone, 'branch' => $branch?->name];
    }

    public function invoice(Request $request, Invoice $invoice): View
    {
        $this->allow($request, ['sales.orders.view', 'receivables.view'], $invoice->branch_id, $invoice->tenant_id);

        return view('print.invoice', ['doc' => $invoice->load('lines'), 'company' => $this->company($invoice->branch_id),
            'customer' => Customer::find($invoice->customer_id),
            'open' => (int) (collect(app(CustomerLedger::class)->openItems($invoice->customer_id))->firstWhere('doc_id', $invoice->id)?->open_minor ?? 0)]);
    }

    public function order(Request $request, CustomerOrder $order): View
    {
        $this->allow($request, ['sales.orders.view'], $order->branch_id, $order->tenant_id);

        return view('print.order', ['doc' => $order->load('lines'), 'company' => $this->company($order->branch_id), 'customer' => Customer::find($order->customer_id)]);
    }

    public function statement(Request $request, Customer $customer): View
    {
        $this->allow($request, ['receivables.view'], null, $customer->tenant_id);
        $from = Carbon::parse($request->query('from', now()->startOfMonth()->toDateString()));
        $to = Carbon::parse($request->query('to', now()->toDateString()));
        $ledger = app(CustomerLedger::class);

        return view('print.statement', ['customer' => $customer, 'company' => $this->company($customer->home_branch_id), 'from' => $from, 'to' => $to,
            'statement' => $ledger->statement($customer->id, $from, $to), 'aging' => $ledger->aging($to, [$customer->id])[$customer->id] ?? null,
            'currency' => Tenant::find($customer->tenant_id)->currency_code]);
    }

    public function payment(Request $request, CustomerPayment $payment): View
    {
        $this->allow($request, ['receivables.view', 'receivables.collect'], $payment->branch_id, $payment->tenant_id);

        return view('print.payment', ['doc' => $payment, 'company' => $this->company($payment->branch_id), 'customer' => Customer::find($payment->customer_id),
            'allocations' => DB::table('receivable_allocations')->where('from_type', 'customer_payment')->where('from_id', $payment->id)
                ->leftJoin('customer_ledger', fn ($j) => $j->on('customer_ledger.doc_id', '=', 'receivable_allocations.to_id')->whereIn('customer_ledger.type', ['invoice', 'pos_sale', 'opening']))
                ->get(['customer_ledger.doc_number', 'receivable_allocations.amount_minor'])]);
    }
}
