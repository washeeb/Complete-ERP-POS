<?php

namespace App\Http\Controllers;

use App\Domain\Customers\CustomerLedger;
use App\Domain\Pharmacy\PharmacyReports;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerPayment;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\LegalEntity;
use App\Models\Markdown;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $entity = $branch?->legal_entity_id ? LegalEntity::find($branch->legal_entity_id) : LegalEntity::primary();

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

    private function allowPayroll(Request $request, string $tenantId, ?string $ownerUserId = null): void
    {
        $user = $request->user();
        abort_unless($user && $user->tenant_id === $tenantId, 404);
        if ($ownerUserId !== null && $ownerUserId === $user->id) {
            return; // your own payslip
        }
        foreach (['payroll.prepare', 'payroll.approve', 'payroll.pay'] as $p) {
            if ($this->authz->allows($user, $p)) {
                return;
            }
        }
        abort(403);
    }

    public function payslip(Request $request, Payslip $payslip): View
    {
        $this->allowPayroll($request, $payslip->tenant_id, Employee::find($payslip->employee_id)?->user_id);
        $run = PayrollRun::findOrFail($payslip->payroll_run_id);
        abort_if($run->status === 'draft' && ! $this->authz->allows($request->user(), 'payroll.prepare') && ! $this->authz->allows($request->user(), 'payroll.approve'), 404);

        return view('print.payslips', ['run' => $run, 'slips' => collect([$payslip]), 'company' => $this->company($payslip->branch_id), 'currency' => Tenant::find($payslip->tenant_id)->currency_code]);
    }

    public function payslips(Request $request, PayrollRun $run): View
    {
        $this->allowPayroll($request, $run->tenant_id);

        return view('print.payslips', ['run' => $run, 'slips' => $run->payslips()->orderByRaw("employee->>'name'")->get(), 'company' => $this->company(null),
            'currency' => Tenant::find($run->tenant_id)->currency_code]);
    }

    /** Pay sheet: everyone's net pay and where it goes; cash staff sign for it. */
    public function paySheet(Request $request, PayrollRun $run): View
    {
        $this->allowPayroll($request, $run->tenant_id);

        return view('print.pay-sheet', ['run' => $run, 'slips' => $run->payslips()->orderByRaw("employee->>'pay_method', employee->>'name'")->get(), 'company' => $this->company(null),
            'currency' => Tenant::find($run->tenant_id)->currency_code]);
    }

    /** Bank and MoMo payment list (CSV) to upload to the bank or MoMo bulk-payment portal. */
    public function payList(Request $request, PayrollRun $run): StreamedResponse
    {
        $this->allowPayroll($request, $run->tenant_id);
        $slips = $run->payslips()->get()->filter(fn ($p) => in_array($p->employee['pay_method'] ?? '', ['bank', 'momo'], true) && $p->net_minor > 0);

        return response()->streamDownload(function () use ($slips, $run) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Employee no.', 'Name', 'Method', 'Bank', 'Account / wallet', 'Amount', 'Reference'], ',', '"', '');
            foreach ($slips as $p) {
                $e = $p->employee;
                fputcsv($out, [$e['number'], $e['name'], $e['pay_method'], $e['bank_name'] ?? '', $e['pay_method'] === 'momo' ? $e['momo_number'] : $e['bank_account_no'],
                    number_format($p->net_minor / 100, 2, '.', ''), "Salary {$run->number}"], ',', '"', '');
            }
            fclose($out);
        }, "salaries-{$run->number}.csv", ['Content-Type' => 'text/csv']);
    }

    /** Markdown stickers (EAN-13 with the reduced price) for near-expiry packs. */
    public function markdownLabels(Request $request): View
    {
        $ids = array_filter(explode(',', (string) $request->query('ids')), fn ($id) => Str::isUuid($id));
        $labels = Markdown::with('product')->whereIn('id', $ids)->get();
        abort_if($labels->isEmpty(), 404);
        $this->allow($request, ['grocery.markdowns'], $labels->first()->branch_id, $labels->first()->tenant_id);

        return view('print.markdown-labels', ['labels' => $labels, 'company' => $this->company($labels->first()->branch_id),
            'currency' => Tenant::find($labels->first()->tenant_id)->currency_code]);
    }

    /** Controlled-drug register for a warehouse and period (Pharmacy): every movement with a running balance. */
    public function controlledRegister(Request $request): View
    {
        $w = Warehouse::findOrFail((string) $request->query('warehouse'));
        $this->allow($request, ['pharmacy.manage', 'pharmacy.dispense'], $w->branch_id, $w->tenant_id);
        [$from, $to] = $this->period($request);

        return view('print.controlled-register', ['warehouse' => $w, 'from' => $from, 'to' => $to, 'products' => PharmacyReports::controlledRegister($w->id, $from, $to),
            'company' => $this->company($w->branch_id), 'licence' => app(Settings::class)->get('pharmacy.premises_licence_no', $w->tenant_id),
            'superintendent' => app(Settings::class)->get('pharmacy.superintendent', $w->tenant_id)]);
    }

    /** NHIS claims list (CSV): medicines dispensed on prescription to NHIS members over a period. */
    public function nhisClaims(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user && $this->authz->allowsAnywhere($user, 'pharmacy.manage'), 403);
        $branchIds = $this->authz->branchIds($user, 'pharmacy.manage');
        [$from, $to] = $this->period($request);
        $rows = PharmacyReports::nhisClaims($branchIds, $from, $to);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Prescription', 'Date', 'Receipt', 'Patient', 'NHIS no.', 'NHIS expires', 'Prescriber', 'Prescriber reg. no.', 'Facility', 'Prescription ref.',
                'Pharmacist', 'Medicine', 'Generic name', 'Strength', 'FDA reg. no.', 'Qty', 'Unit', 'Amount'], ',', '"', '');
            foreach ($rows as $r) {
                fputcsv($out, [$r->number, Carbon::parse($r->occurred_at)->toDateString(), $r->receipt_no, $r->patient, $r->nhis_no, $r->nhis_expires_on, $r->prescriber_name,
                    $r->prescriber_reg_no, $r->facility, $r->reference, $r->pharmacist_name, $r->medicine, $r->generic_name, $r->strength, $r->fda_reg_no,
                    rtrim(rtrim((string) $r->qty, '0'), '.'), $r->uom_code, number_format($r->line_total_minor / 100, 2, '.', '')], ',', '"', '');
            }
            fclose($out);
        }, "nhis-claims-{$from}-to-{$to}.csv", ['Content-Type' => 'text/csv']);
    }

    /** @return array{0: string, 1: string} */
    private function period(Request $request): array
    {
        $from = Carbon::parse((string) $request->query('from', now()->startOfMonth()->toDateString()))->toDateString();
        $to = Carbon::parse((string) $request->query('to', now()->toDateString()))->toDateString();
        abort_if($from > $to, 422);

        return [$from, $to];
    }
}
