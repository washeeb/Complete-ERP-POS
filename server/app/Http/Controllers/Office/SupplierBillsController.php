<?php

namespace App\Http\Controllers\Office;

use App\Domain\Ai\InvoiceScanner;
use App\Domain\Office\SupplierBillsScreen;
use App\Domain\Payables\BillService;
use App\Domain\Purchasing\SupplierPortal;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ConfirmItsYou;
use App\Filament\Resources\SupplierBills\SupplierBillResource;
use App\Filament\Support\QuickCreate;
use App\Http\Controllers\Controller;
use App\Models\AiDocument;
use App\Models\Branch;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierInvoice;
use App\Security\ReconfirmNeeded;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Supplier bills in the new back office (App\Domain\Office\SupplierBillsScreen): the list, one bill with Approve and
 * Reject, Enter a bill (by hand, from a scanned invoice, or from one the supplier sent), and Scan an invoice, through
 * BillService, InvoiceScanner and SupplierPortal as the current screens.
 */
class SupplierBillsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new SupplierBillsScreen($request->user()))->list($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new SupplierBillsScreen($request->user()))->options());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->allowed();

        return response()->json((new SupplierBillsScreen($request->user()))->show($this->find($id)));
    }

    public function form(Request $request): JsonResponse
    {
        $this->allowed();
        abort_unless(SupplierBillsScreen::canCreate(), 403, 'You cannot enter supplier bills.');
        $d = $request->validate(['scan' => ['nullable', 'uuid'], 'invoice' => ['nullable', 'uuid']]);

        return response()->json((new SupplierBillsScreen($request->user()))->form($d['scan'] ?? null, $d['invoice'] ?? null));
    }

    /** What the supplier can still be billed for (goods received and services on orders). */
    public function billable(Request $request): JsonResponse
    {
        $this->allowed();
        abort_unless(SupplierBillsScreen::canCreate(), 403);

        return response()->json((new SupplierBillsScreen($request->user()))->billable(Supplier::findOrFail((string) $request->query('supplier'))->id));
    }

    /** A supplier added from the bill form; one already there under that name or code is chosen instead (as the current form). */
    public function addSupplier(Request $request): JsonResponse
    {
        $this->allowed();
        $d = $request->validate(['name' => ['required', 'string', 'max:255'], 'code' => ['nullable', 'string', 'max:20'], 'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255']]);
        if ($existing = QuickCreate::existing('supplier', $d)) {
            return response()->json(['id' => $existing->id, 'label' => $existing->name, 'existed' => true]);
        }

        return $this->act($request, function (Actor $actor) use ($d) {
            $s = app(SupplierService::class)->save($actor, new Supplier, ['code' => $d['code'] ?? null, 'name' => $d['name'], 'phone' => $d['phone'] ?? null, 'email' => $d['email'] ?? null]);

            return ['id' => $s->id, 'label' => $s->name, 'existed' => false];
        });
    }

    /** Scan an invoice: the AI reads it into a draft bill; the answer says which (open the form with it). */
    public function scan(Request $request, InvoiceScanner $scanner): JsonResponse
    {
        $this->allowed();
        abort_unless(SupplierBillsScreen::canCreate(), 403, 'You cannot enter supplier bills.');
        $d = $request->validate(['branch_id' => ['required', 'uuid'], 'file' => ['required', 'file', 'mimetypes:'.implode(',', InvoiceScanner::TYPES), 'max:10240']],
            ['file.required' => 'Attach the invoice.', 'file.mimetypes' => 'Attach a photo (JPEG, PNG, WebP) or a PDF.', 'file.max' => 'The file is larger than 10 MB.']);
        $file = $request->file('file');
        $path = $file->store('ai-documents', 'local');
        $answer = $this->act($request, fn (Actor $actor) => ['scan' => $scanner->scan($actor, Branch::findOrFail($d['branch_id']), $path, mb_substr($file->getClientOriginalName(), 0, 255))->id]);
        if ($answer->getStatusCode() !== 200 && ! AiDocument::where('file_path', $path)->exists()) {
            Storage::disk('local')->delete($path); // refused before it was kept
        }

        return $answer;
    }

    /** Enter the bill; a scanned invoice is marked used, and an invoice the supplier sent is marked entered (as now). */
    public function save(Request $request, BillService $bills): JsonResponse
    {
        $this->allowed();
        abort_unless(SupplierBillsScreen::canCreate(), 403, 'You cannot enter supplier bills.');
        $d = $request->validate([
            'values' => ['required', 'array'], 'values.supplier_id' => ['required', 'uuid'], 'values.supplier_invoice_no' => ['required', 'string', 'max:60'],
            'values.branch_id' => ['required', 'uuid'], 'values.bill_date' => ['required', 'date', 'before_or_equal:today'], 'values.due_on' => ['nullable', 'date'],
            'values.note' => ['nullable', 'string', 'max:2000'],
            'values.stock_lines' => ['array'], 'values.stock_lines.*.goods_receipt_line_id' => ['required', 'uuid'], 'values.stock_lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'values.stock_lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'values.order_lines' => ['array'], 'values.order_lines.*.purchase_order_line_id' => ['required', 'uuid'], 'values.order_lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'values.order_lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'values.expense_lines' => ['array'], 'values.expense_lines.*.account_id' => ['required', 'uuid'], 'values.expense_lines.*.description' => ['nullable', 'string', 'max:255'],
            'values.expense_lines.*.amount' => ['required', 'numeric', 'gt:0'], 'values.expense_lines.*.tax_group_id' => ['nullable', 'uuid'],
            'values.expense_lines.*.department_id' => ['nullable', 'uuid'], 'values.expense_lines.*.project_id' => ['nullable', 'uuid'], 'values.expense_lines.*.maintenance_order_id' => ['nullable', 'uuid'],
            'scan' => ['nullable', 'uuid'], 'invoice' => ['nullable', 'uuid'],
        ], ['values.supplier_id.required' => 'Choose the supplier.', 'values.supplier_invoice_no.required' => 'Enter the supplier’s invoice number.',
            'values.expense_lines.*.account_id.required' => 'Choose the account on every charge.', 'values.stock_lines.*.goods_receipt_line_id.required' => 'Choose what was received on every line.',
            'values.order_lines.*.purchase_order_line_id.required' => 'Choose the order line on every service line.']);
        $v = $d['values'];
        $blank = fn ($x) => is_string($x) && trim($x) === '' ? null : $x;

        return $this->act($request, function (Actor $actor) use ($bills, $v, $d, $blank) {
            $bill = $bills->create($actor, Supplier::findOrFail($v['supplier_id']), Branch::findOrFail($v['branch_id']), SupplierBillResource::toDomain([
                'supplier_invoice_no' => $v['supplier_invoice_no'], 'bill_date' => $v['bill_date'], 'due_on' => $blank($v['due_on'] ?? null), 'note' => $blank($v['note'] ?? null),
                'stock_lines' => $v['stock_lines'] ?? [], 'order_lines' => $v['order_lines'] ?? [],
                'expense_lines' => array_map(fn ($l) => array_map($blank, $l), $v['expense_lines'] ?? []),
            ]));
            if (($d['scan'] ?? null) && ($doc = AiDocument::find($d['scan']))) {
                app(InvoiceScanner::class)->used($doc, $bill->id);
            }
            $sentId = $d['invoice'] ?? (($d['scan'] ?? null) ? (AiDocument::find($d['scan'])?->form['supplier_invoice_id'] ?? null) : null);
            if ($sentId && ($sent = SupplierInvoice::where('status', 'received')->find($sentId))) {
                app(SupplierPortal::class)->markEntered($actor, $sent, $bill->id);
            }

            return ['id' => $bill->id];
        });
    }

    /** approve (reason, optional) · reject (reason) */
    public function action(Request $request, string $id, BillService $bills): JsonResponse
    {
        $this->allowed();
        $a = $request->validate(['action' => ['required', Rule::in(['approve', 'reject'])]])['action'];
        $d = $request->validate(['reason' => [$a === 'reject' ? 'required' : 'nullable', 'string', 'max:255']], ['reason.required' => 'Say why.']);
        $b = $this->find($id);
        abort_unless($b->created_by !== $request->user()->id, 422, 'You entered this bill, so someone else must approve or reject it.');

        return $this->act($request, function (Actor $actor) use ($a, $d, $b, $bills) {
            $a === 'approve' ? $bills->approve($actor, $b, $d['reason'] ?? null) : $bills->reject($actor, $b, $d['reason']);

            return (new SupplierBillsScreen($actor->user))->show($b->fresh());
        });
    }

    private function find(string $id): SupplierBill
    {
        return SupplierBillResource::getEloquentQuery()->findOrFail($id);
    }

    private function act(Request $request, Closure $fn): JsonResponse
    {
        $actor = Actor::user($request->user(), 'web');
        try {
            return response()->json(DB::transaction(fn () => $fn($actor)));
        } catch (ReconfirmNeeded $e) {
            return response()->json(['error' => 'reconfirm', 'detail' => $e->getMessage(), 'url' => ConfirmItsYou::getUrl()], 428);
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage() ?: 'You may not do this here.'], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
    }

    private function allowed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(SupplierBillsScreen::canView(), 403, 'You cannot see supplier bills.');
    }
}
