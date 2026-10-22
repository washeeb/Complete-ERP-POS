<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\Labels;
use App\Domain\Customers\CustomerLedger;
use App\Domain\Logistics\WaybillService;
use App\Domain\Pharmacy\PharmacyReports;
use App\Domain\Support\Actor;
use App\Domain\Support\AuditLog;
use App\Filament\Support\Fields;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerPayment;
use App\Models\Employee;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\LegalEntity;
use App\Models\Markdown;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Sale;
use App\Models\StockAdjustment;
use App\Models\StockRequest;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Models\Waybill;
use App\Security\Authorizer;
use App\Support\Qr;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        return self::companyOf(auth()->user()->tenant_id, $branchId);
    }

    /** The letterhead: the legal entity of the branch (else the company), its address and phone. Also used for PDFs sent out. */
    public static function companyOf(string $tenantId, ?string $branchId): array
    {
        $tenant = Tenant::withoutGlobalScopes()->find($tenantId);
        $branch = $branchId ? Branch::withoutGlobalScopes()->find($branchId) : Branch::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_hq', true)->first();
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

    /**
     * A copy of a till receipt (80 mm, or A4 from the print window), clearly marked COPY with who reprinted it and when;
     * every reprint is in the audit log. ?print=1 opens the print window straight away.
     */
    public function receipt(Request $request, Sale $sale): View
    {
        $this->allow($request, ['sales.view'], $sale->branch_id, $sale->tenant_id);
        $user = $request->user();
        DB::transaction(fn () => app(AuditLog::class)->record(Actor::user($user), 'sale.receipt_reprinted', $sale, [], null, $sale->branch_id));

        return view('print.receipt', ['sale' => $sale->load('lines', 'payments', 'till'), 'company' => $this->company($sale->branch_id),
            'footer' => (string) app(Settings::class)->get('receipt.footer', $sale->tenant_id), 'by' => $user->name, 'autoPrint' => $request->boolean('print')]);
    }

    public function order(Request $request, CustomerOrder $order): View
    {
        $this->allow($request, ['sales.orders.view'], $order->branch_id, $order->tenant_id);

        return view('print.order', ['doc' => $order->load('lines'), 'company' => $this->company($order->branch_id), 'customer' => Customer::find($order->customer_id)]);
    }

    public function purchaseOrder(Request $request, PurchaseOrder $po): View
    {
        $this->allow($request, ['purchasing.view', 'purchasing.manage'], $po->branch_id, $po->tenant_id);

        return view('print.purchase-order', ['doc' => $po->load('lines'), 'company' => $this->company($po->branch_id),
            'supplier' => Supplier::find($po->supplier_id), 'warehouse' => Warehouse::find($po->warehouse_id)]);
    }

    public function goodsReceipt(Request $request, GoodsReceipt $grn): View
    {
        $this->allow($request, ['purchasing.view', 'stock.receive'], $grn->branch_id, $grn->tenant_id);
        $grn->load('lines');
        $supplier = Supplier::find($grn->supplier_id);

        return $this->stockDoc($grn->branch_id, 'Goods received', $grn->number, ($grn->received_at ?? $grn->created_at)->format('j M Y H:i'), GoodsReceipt::STATUSES[$grn->status] ?? $grn->status,
            [['Supplier', array_filter([$supplier?->name, $grn->supplier_ref ? "Delivery note {$grn->supplier_ref}" : null])],
                ['Received into', [Warehouse::find($grn->warehouse_id)?->name, $grn->purchase_order_id ? 'Purchase order '.PurchaseOrder::find($grn->purchase_order_id)?->number : null]]],
            [['#', false], ['Item', false], ['Quantity', true]],
            $grn->lines->map(fn ($l) => [$l->line_no, e($l->name).'<br><span class="small muted">'.e($l->sku).'</span>', e(Fields::qty((string) $l->qty, $l->uom_code))])->all(),
            $grn->note, [['Received by', $grn->received_by_name], ['Checked by', $grn->approved_by_name ?? '']]);
    }

    public function transfer(Request $request, StockTransfer $t): View
    {
        $this->allow($request, ['stock.transfer', 'stock.view'], $t->from_branch_id, $t->tenant_id);
        $t->load('lines');

        return $this->stockDoc($t->from_branch_id, 'Stock transfer', $t->number, $t->created_at->format('j M Y'), StockTransfer::STATUSES[$t->status] ?? $t->status,
            [['From', [Warehouse::find($t->from_warehouse_id)?->name]], ['To', [Warehouse::find($t->to_warehouse_id)?->name]]],
            [['#', false], ['Item', false], ['Sent', true], ['Received', true]],
            $t->lines->map(fn ($l) => [$l->line_no, e($l->name).'<br><span class="small muted">'.e($l->sku).'</span>', e(Fields::qty((string) $l->qty, $l->uom_code)),
                $l->received_qty === null ? '' : e(Fields::qty((string) $l->received_qty, $l->uom_code))])->all(),
            $t->note, [['Sent by', $t->dispatched_by_name ?? $t->created_by_name], ['Received by', $t->received_by_name ?? '']]);
    }

    public function stockRequest(Request $request, StockRequest $r): View
    {
        $this->allow($request, ['stock.request', 'stock.transfer', 'stock.view'], $r->to_branch_id, $r->tenant_id);
        $r->load('lines');

        return $this->stockDoc($r->to_branch_id, 'Stock request', $r->number, $r->created_at->format('j M Y'), StockRequest::STATUSES[$r->status] ?? $r->status,
            [['Asked by', [Warehouse::find($r->to_warehouse_id)?->name, $r->requested_by_name]], ['Asked of', [Warehouse::find($r->from_warehouse_id)?->name]]],
            [['#', false], ['Item', false], ['Asked for', true], ['Sent', true]],
            $r->lines->map(fn ($l) => [$l->line_no, e($l->name).'<br><span class="small muted">'.e($l->sku).'</span>', e(Fields::qty((string) $l->qty, $l->uom_code)),
                $l->supplied_qty === null ? '' : e(Fields::qty((string) $l->supplied_qty, $l->uom_code))])->all(),
            $r->note, [['Asked by', $r->requested_by_name], ['Sent by', $r->supplied_by_name ?? '']]);
    }

    public function purchaseRequest(Request $request, PurchaseRequest $r): View
    {
        $this->allow($request, ['purchasing.request', 'purchasing.manage'], $r->branch_id, $r->tenant_id);
        $r->load('lines');

        return $this->stockDoc($r->branch_id, 'Purchase request', $r->number, $r->created_at->format('j M Y'), PurchaseRequest::STATUSES[$r->status] ?? $r->status,
            [['For the store', array_filter([Warehouse::find($r->warehouse_id)?->name, $r->needed_by ? 'Needed by '.$r->needed_by->format('j M Y') : null])], ['Asked by', [$r->requested_by_name]]],
            [['#', false], ['Item', false], ['Quantity', true], ['Note', false]],
            $r->lines->map(fn ($l) => [$l->line_no, e($l->name).'<br><span class="small muted">'.e($l->sku).'</span>', e(Fields::qty((string) $l->qty, $l->uom_code)), e((string) $l->note)])->all(),
            $r->note, [['Asked by', $r->requested_by_name], ['Ordered by', $r->status === 'ordered' ? $r->closed_by_name : '']]);
    }

    private function stockDoc(?string $branchId, string $title, string $number, string $date, ?string $status, array $boxes, array $columns, array $rows, ?string $note, array $signatures): View
    {
        return view('print.stock-document', ['company' => $this->company($branchId), 'title' => $title, 'number' => $number, 'date' => $date, 'status' => $status,
            'boxes' => $boxes, 'columns' => $columns, 'rows' => $rows, 'note' => $note, 'signatures' => $signatures]);
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

    /** Barcode and shelf labels prepared from the products list or a delivery (Labels::prepare), for the person who prepared them. */
    public function labels(Request $request, string $token): View
    {
        $job = Labels::job($token);
        abort_unless($job && $job['user_id'] === $request->user()?->id, 404);
        $this->allow($request, ['catalog.view'], null, $request->user()->tenant_id);
        $o = $job['options'];

        return view('print.labels', ['labels' => app(Labels::class)->rows($request->user()->tenant_id, $job['products'], $o['price_list_id'] ?? null, (int) ($o['copies'] ?? 1)),
            'kind' => $o['kind'], 'layout' => $o['layout'], 'skip' => Labels::LAYOUTS[$o['layout']][2] === 0 ? 0 : max(0, min(64, (int) ($o['skip'] ?? 0)))]);
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

    /** Return note for the supplier: what goes back, signed by the store and the supplier's representative. */
    public function supplierReturn(Request $request, SupplierReturn $return): View
    {
        $this->allow($request, ['stock.expiry', 'stock.adjust.approve', 'payables.view'], $return->branch_id, $return->tenant_id);

        return view('print.supplier-return', ['r' => $return->load('lines.product.baseUom', 'supplier', 'warehouse'), 'company' => $this->company($return->branch_id)]);
    }

    /** Record of a disposal of expired or damaged stock (method, witness, signatures), for the premises file and FDA inspections. */
    public function disposal(Request $request, StockAdjustment $adjustment): View
    {
        abort_unless($adjustment->kind === 'disposal', 404);
        $this->allow($request, ['stock.expiry', 'stock.adjust.approve', 'stock.view'], $adjustment->branch_id, $adjustment->tenant_id);

        return view('print.disposal', ['a' => $adjustment->load('lines.product.baseUom', 'warehouse'), 'company' => $this->company($adjustment->branch_id)]);
    }

    /** Disposal register: every disposal approved in a period, for a store. */
    public function disposalRegister(Request $request): View
    {
        $w = Warehouse::findOrFail((string) $request->query('warehouse'));
        $this->allow($request, ['stock.expiry', 'stock.adjust.approve'], $w->branch_id, $w->tenant_id);
        [$from, $to] = $this->period($request);
        $tz = Tenant::find($w->tenant_id)->timezone ?: 'UTC';
        $disposals = StockAdjustment::with('lines.product.baseUom')->where('warehouse_id', $w->id)->where('kind', 'disposal')->where('status', 'posted')
            ->whereBetween('posted_at', [Carbon::parse($from, $tz)->startOfDay()->utc(), Carbon::parse($to, $tz)->endOfDay()->utc()])->orderBy('posted_at')->get();

        return view('print.disposal-register', ['warehouse' => $w, 'from' => $from, 'to' => $to, 'disposals' => $disposals, 'company' => $this->company($w->branch_id)]);
    }

    /** A waybill to print and travel with the goods; while they are on the way it carries the receiver's QR code. */
    public function waybill(Request $request, Waybill $waybill): View
    {
        $this->allow($request, [WaybillService::PERMISSIONS[$waybill->kind], 'stock.view'], $waybill->branch_id, $waybill->tenant_id);
        $qr = $waybill->confirm_token && in_array($waybill->status, ['prepared', 'dispatched'], true) ? Qr::svg(route('waybill.confirm', $waybill->confirm_token)) : null;

        return view('print.waybill', ['w' => $waybill, 'qr' => $qr, 'company' => $this->company($waybill->branch_id)]);
    }

    public function waybillPod(Request $request, Waybill $waybill): StreamedResponse
    {
        $this->allow($request, [WaybillService::PERMISSIONS[$waybill->kind], 'stock.view'], $waybill->branch_id, $waybill->tenant_id);
        abort_unless($waybill->pod_path && Storage::disk('local')->exists($waybill->pod_path), 404);

        return Storage::disk('local')->response($waybill->pod_path);
    }

    public function disposalCertificate(Request $request, StockAdjustment $adjustment): StreamedResponse
    {
        $this->allow($request, ['stock.expiry', 'stock.adjust.approve', 'stock.view'], $adjustment->branch_id, $adjustment->tenant_id);
        abort_unless($adjustment->certificate_path && Storage::disk('local')->exists($adjustment->certificate_path), 404);

        return Storage::disk('local')->response($adjustment->certificate_path);
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
