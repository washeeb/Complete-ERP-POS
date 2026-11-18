<?php

namespace App\Domain\Office;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\InvoiceScanner;
use App\Domain\Projects\Projects;
use App\Domain\Service\ServiceDesk;
use App\Filament\Resources\SupplierBills\SupplierBillResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Models\AiDocument;
use App\Models\Department;
use App\Models\GoodsReceipt;
use App\Models\MaintenanceOrder;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierInvoice;
use App\Models\TaxGroup;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Supplier bills in the new back office (release 6 of the rewrite): the list with the current screen's search, status
 * filter and columns (with the difference from the order prices), one bill with Approve and Reject, and Enter a bill —
 * goods received (3-way match), services confirmed on orders, and other charges straight to an account — filled in by
 * hand, from a scanned invoice (the AI) or from an invoice the supplier sent on their portal. Same rules: BillService,
 * InvoiceScanner and SupplierPortal (SupplierBillsController).
 */
final class SupplierBillsScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const SORTS = ['number', 'bill_date', 'due_on', 'total_minor'];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return SupplierBillResource::canViewAny();
    }

    public static function canCreate(): bool
    {
        return SupplierBillResource::canCreate();
    }

    public function filtered(array $p): Builder
    {
        $q = SupplierBillResource::getEloquentQuery();
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn (Builder $w) => $w->where('supplier_bills.number', 'ilike', "%{$search}%")->orWhere('supplier_bills.supplier_invoice_no', 'ilike', "%{$search}%")
                ->orWhereHas('supplier', fn (Builder $s) => $s->where('name', 'ilike', "%{$search}%")));
        }
        if (isset(SupplierBill::STATUSES[$p['status'] ?? ''])) {
            $q->where('supplier_bills.status', $p['status']);
        }
        if (filled($p['supplier'] ?? null)) {
            $q->where('supplier_bills.supplier_id', (string) $p['supplier']);
        }

        return $q;
    }

    /** @return array{0: string, 1: string} */
    public function order(array $p): array
    {
        return [in_array($p['sort'] ?? null, self::SORTS, true) ? $p['sort'] : 'number', ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc'];
    }

    public function sorted(array $p): Builder
    {
        [$sort, $dir] = $this->order($p);

        return $this->filtered($p)->orderBy("supplier_bills.{$sort}", $dir)->orderBy('supplier_bills.id', $dir);
    }

    public function rowOf(SupplierBill $b): array
    {
        return [
            'id' => $b->id, 'number' => $b->number, 'supplier' => $b->supplier?->name, 'invoice_no' => $b->supplier_invoice_no,
            'bill_date' => $b->bill_date?->toDateString(), 'due_on' => $b->due_on?->toDateString(), 'total' => (int) $b->total_minor,
            'variance' => (int) $b->variance_minor, 'status' => $b->status,
        ];
    }

    /** @param  array<string, mixed>  $p  q, status, supplier, sort, dir, page, per */
    public function list(array $p): array
    {
        $t = (clone $this->filtered($p))->toBase()->reorder()->selectRaw("COUNT(*) AS n, COALESCE(SUM(total_minor) FILTER (WHERE status = 'posted'), 0) AS posted, "
            ."COUNT(*) FILTER (WHERE status = 'pending_approval') AS waiting")->first();
        [$sort, $dir] = $this->order($p);
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $currency = $this->currency();

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency),
            'total' => (int) $t->n, 'posted' => (int) $t->posted, 'waiting' => (int) $t->waiting,
            'page' => $page, 'per' => $per, 'sort' => $sort, 'dir' => $dir,
            'rows' => $this->sorted($p)->forPage($page, $per)->get()->map(fn (SupplierBill $b) => $this->rowOf($b))->all(),
        ];
    }

    public function options(): array
    {
        return [
            'statuses' => PurchaseOrdersScreen::choices(SupplierBill::STATUSES),
            'suppliers' => PurchaseOrdersScreen::choices(Supplier::orderBy('name')->pluck('name', 'id')->all()),
            'per' => self::PER_PAGE, 'can_create' => self::canCreate(),
            'scan' => self::canCreate() ? ['branches' => PurchaseOrdersScreen::choices(FinanceUi::branches()), 'hq' => (string) FinanceUi::hq(),
                'ai_unavailable' => app(AiGateway::class)->unavailableReason($this->user->tenant_id, 'vision')] : null,
        ];
    }

    /** One bill: what the current page shows, and Approve / Reject (someone other than whoever entered it). */
    public function show(SupplierBill $b): array
    {
        $b->loadMissing(['supplier', 'lines.account']);
        $currency = $this->currency();
        $decide = $b->status === 'pending_approval' && Erp::canAnywhere('payables.approve') && $b->created_by !== $this->user->id;

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency),
            'id' => $b->id, 'number' => $b->number, 'status' => $b->status, 'status_label' => SupplierBill::STATUSES[$b->status] ?? $b->status,
            'supplier' => ['name' => $b->supplier?->name, 'open' => SuppliersScreen::canView() && $b->supplier_id ? "/office/suppliers/{$b->supplier_id}" : null],
            'invoice_no' => $b->supplier_invoice_no, 'bill_date' => $b->bill_date?->toDateString(), 'due_on' => $b->due_on?->toDateString(),
            'net' => (int) $b->net_minor, 'tax' => (int) $b->tax_minor, 'total' => (int) $b->total_minor, 'variance' => (int) $b->variance_minor,
            'created_by' => $b->created_by_name, 'decided_by' => $b->approved_by_name, 'variance_reason' => $b->variance_reason, 'note' => $b->note,
            'lines' => $b->lines->map(fn ($l) => [
                'description' => $l->account ? $l->account->label().' · '.$l->description : $l->description, 'qty' => Fields::qty($l->qty),
                'price' => (int) $l->unit_cost_minor, 'order_price' => $l->order_cost_minor !== null && $l->order_cost_minor !== $l->unit_cost_minor ? (int) $l->order_cost_minor : null,
                'tax' => (int) $l->tax_minor, 'total' => (int) $l->total_minor])->values()->all(),
            'actions' => ['approve' => $decide, 'reject' => $decide],
        ];
    }

    /**
     * Enter a bill: empty, or filled from a scanned invoice (scan: AiDocument id) or from an invoice the supplier sent
     * on their portal (invoice: SupplierInvoice id), as the current form.
     */
    public function form(?string $scan, ?string $invoice): array
    {
        $doc = $scan ? AiDocument::where('status', 'read')->find($scan) : null;
        $sent = $invoice ? SupplierInvoice::where('status', 'received')->find($invoice) : null;
        $values = ['supplier_id' => '', 'supplier_invoice_no' => '', 'branch_id' => (string) FinanceUi::hq(), 'bill_date' => now()->toDateString(), 'due_on' => '', 'note' => '',
            'stock_lines' => [], 'order_lines' => [], 'expense_lines' => []];
        $notice = null;
        $checks = [];
        if ($doc) {
            $f = (array) $doc->form;
            $values = array_merge($values, array_map(fn ($x) => $x ?? '', array_intersect_key($f, $values)));
            foreach (['stock_lines', 'order_lines', 'expense_lines'] as $k) {
                $values[$k] = array_map(fn ($l) => array_map(fn ($x) => $x === null ? '' : (string) $x, (array) $l), (array) ($f[$k] ?? []));
            }
            $notice = 'Filled in from the scanned invoice: check the supplier, every line and the totals against the paper before you create the bill.';
            $checks = InvoiceScanner::checks($doc);
        } elseif ($sent) {
            $values = array_merge($values, ['supplier_id' => $sent->supplier_id, 'supplier_invoice_no' => $sent->invoice_no, 'bill_date' => $sent->invoice_date->toDateString(),
                'branch_id' => (string) ($sent->purchaseOrder?->branch_id ?? FinanceUi::hq()), 'note' => "Sent on the supplier portal by {$sent->sent_by_name}"]);
            $notice = 'From the invoice the supplier sent: add the lines from it (open it from Invoices from suppliers). When you create the bill, that invoice is marked entered.';
        }

        return [
            'currency' => $this->currency(), 'values' => $values, 'notice' => $notice, 'checks' => $checks,
            'scan' => $doc?->id, 'invoice' => $sent?->id ?? ($doc ? ($doc->form['supplier_invoice_id'] ?? null) : null),
            'choices' => [
                'suppliers' => PurchaseOrdersScreen::choices(Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
                'branches' => PurchaseOrdersScreen::choices(FinanceUi::branches()),
                'accounts' => PurchaseOrdersScreen::choices(FinanceUi::accounts(withControl: false, types: ['expense', 'asset'])),
                'tax_groups' => PurchaseOrdersScreen::choices(TaxGroup::orderBy('code')->pluck('name', 'id')->all()),
                'departments' => PurchaseOrdersScreen::choices(Department::whereNull('archived_at')->orderBy('name')->pluck('name', 'id')->all()),
                'projects' => Projects::enabled() ? PurchaseOrdersScreen::choices(Project::whereIn('status', ['planned', 'active', 'on_hold'])->orderBy('number')->get()
                    ->mapWithKeys(fn ($p) => [$p->id => "{$p->number} {$p->name}"])->all()) : null,
                'work_orders' => ServiceDesk::enabled() ? PurchaseOrdersScreen::choices(MaintenanceOrder::whereIn('status', ['open', 'working', 'waiting'])->orderBy('number')->get()
                    ->mapWithKeys(fn ($o) => [$o->id => "{$o->number} {$o->title}"])->all()) : null,
            ],
            'can_add_supplier' => Erp::canAnywhere('suppliers.manage'),
        ];
    }

    /** What a supplier can still be billed for: goods received lines and services confirmed on orders (as the current form). */
    public function billable(string $supplierId): array
    {
        $currency = $this->currency();
        $fmt = fn (int $minor) => Money::format($minor, $currency);
        $lines = SupplierBillResource::billableLines($supplierId);
        $grnOf = DB::table('goods_receipt_lines')->whereIn('id', array_keys($lines))->pluck('goods_receipt_id', 'id'); // one query for the list

        return [
            'grns' => PurchaseOrdersScreen::choices(GoodsReceipt::where('supplier_id', $supplierId)->where('status', 'posted')->orderBy('number')->pluck('number', 'id')->all()),
            'stock' => array_values(array_map(fn ($l) => ['id' => $l['goods_receipt_line_id'], 'label' => $l['label'], 'qty' => Fields::qty($l['open_qty']),
                'cost' => $fmt((int) $l['order_cost_minor']), 'grn' => $grnOf[$l['goods_receipt_line_id']] ?? null], $lines)),
            'orders' => array_values(array_map(fn ($l) => ['id' => $l['purchase_order_line_id'], 'label' => "{$l['order']} · {$l['name']} — ".Fields::qty($l['open_qty'], $l['uom']).' to bill',
                'qty' => Fields::qty($l['open_qty']), 'cost' => $fmt((int) $l['order_cost_minor'])], SupplierBillResource::billableOrderLines($supplierId))),
        ];
    }

    private function currency(): string
    {
        return $this->user->tenant?->currency_code ?? 'GHS';
    }
}
