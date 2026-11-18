<?php

namespace App\Domain\Office;

use App\Domain\Ai\AiGateway;
use App\Domain\Fashion\ConsignmentService;
use App\Filament\Pages\ConsignmentToPay;
use App\Filament\Resources\SupplierBills\SupplierBillResource;
use App\Filament\Resources\SupplierInvoices\SupplierInvoiceResource;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\SupplierBill;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * Invoices suppliers sent through their portal, and Consignment to pay, in the new back office (release 5 of the
 * rewrite). The invoices list has the current screen's columns, status filter (To enter by default) and buttons — open
 * the invoice, read it with the AI or enter it as a bill (both on the current bill form, not rebuilt yet), mark it
 * entered, turn it back — through SupplierPortal and InvoiceScanner. Consignment to pay lists what each supplier is
 * owed for consigned goods sold, and Settle raises the supplier bill (ConsignmentService).
 */
final class SupplierInvoicesScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return SupplierInvoiceResource::canViewAny();
    }

    public static function canManage(): bool
    {
        return Erp::canAnywhere('payables.manage');
    }

    public static function canConsignment(): bool
    {
        return ConsignmentToPay::canAccess();
    }

    /** '' = To enter (the current default), 'all', or a status. */
    public function filtered(array $p): Builder
    {
        $q = SupplierInvoiceResource::getEloquentQuery();
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn (Builder $w) => $w->where('supplier_invoices.invoice_no', 'ilike', "%{$search}%")
                ->orWhereHas('supplier', fn (Builder $s) => $s->where('name', 'ilike', "%{$search}%")));
        }
        $status = (string) ($p['status'] ?? '');
        if ($status === '') {
            $q->where('supplier_invoices.status', 'received');
        } elseif (isset(SupplierInvoice::STATUSES[$status])) {
            $q->where('supplier_invoices.status', $status);
        }

        return $q;
    }

    public function sorted(array $p): Builder
    {
        $dir = ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $this->filtered($p)->orderBy('supplier_invoices.created_at', $dir)->orderBy('supplier_invoices.id', $dir);
    }

    public function rowOf(SupplierInvoice $r): array
    {
        $waiting = $r->status === 'received' && self::canManage();
        $aiOk = $r->file_path && app(AiGateway::class)->unavailableReason($r->tenant_id, 'vision') === null;

        return [
            'id' => $r->id, 'received_at' => $r->created_at?->toIso8601String(), 'supplier' => $r->supplier?->name, 'invoice_no' => $r->invoice_no,
            'invoice_date' => $r->invoice_date?->toDateString(), 'currency' => $r->currency_code, 'amount' => (int) $r->amount_minor,
            'order' => $r->purchaseOrder ? ['number' => $r->purchaseOrder->number, 'open' => PurchaseOrdersScreen::canView() ? "/office/purchase-orders/{$r->purchase_order_id}" : null] : null,
            'sent_by' => $r->sent_by_name, 'status' => $r->status, 'note' => $r->status === 'entered' ? $r->bill?->number : ($r->status === 'rejected' ? $r->reject_reason : null),
            'actions' => [
                'file' => $r->file_path ? route('supplier-invoice.file', $r->id, false) : null,
                'read' => $waiting && $aiOk,
                'enter' => $waiting ? ProductsScreen::path(SupplierBillResource::getUrl('create', ['invoice' => $r->id])) : null,
                'mark' => $waiting, 'reject' => $waiting,
            ],
        ];
    }

    /** @param  array<string, mixed>  $p  q, status (''|all|received|entered|rejected), dir, page, per */
    public function list(array $p): array
    {
        $t = (clone $this->filtered($p))->toBase()->reorder()->selectRaw('COUNT(*) AS n, COALESCE(SUM(amount_minor), 0) AS amount')->first();
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $currency = $this->user->tenant?->currency_code ?? 'GHS';

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency), 'total' => (int) $t->n, 'amount' => (int) $t->amount,
            'waiting' => (clone SupplierInvoiceResource::getEloquentQuery())->where('status', 'received')->count(),
            'page' => $page, 'per' => $per, 'sort' => 'created_at', 'dir' => ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
            'rows' => $this->sorted($p)->forPage($page, $per)->get()->map(fn (SupplierInvoice $r) => $this->rowOf($r))->all(),
        ];
    }

    public function options(): array
    {
        return ['statuses' => PurchaseOrdersScreen::choices(SupplierInvoice::STATUSES), 'per' => self::PER_PAGE, 'consignment' => self::canConsignment()];
    }

    /** Mark entered: the supplier's last bills to choose from. */
    public function bills(SupplierInvoice $r): array
    {
        return SupplierBill::where('supplier_id', $r->supplier_id)->orderByDesc('created_at')->limit(50)->get()
            ->map(fn (SupplierBill $b) => ['id' => $b->id, 'label' => "{$b->number} · {$b->supplier_invoice_no} · ".Money::display((int) $b->total_minor, $b->currency_code)])->all();
    }

    /** Consignment to pay: what each supplier is owed for consigned goods sold, per branch. */
    public function consignment(): array
    {
        $codes = Branch::query()->pluck('code', 'id');
        $currency = $this->user->tenant?->currency_code ?? 'GHS';

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency),
            'rows' => ConsignmentService::owed()->map(fn ($r) => [
                'key' => "{$r->consignor_id}|{$r->branch_id}", 'supplier' => $r->name, 'supplier_open' => SuppliersScreen::canView() ? "/office/suppliers/{$r->consignor_id}" : null,
                'branch' => $codes[$r->branch_id] ?? '—', 'qty' => rtrim(rtrim((string) $r->qty, '0'), '.'), 'sold' => (int) $r->sold_minor, 'owed' => (int) $r->owed_minor,
            ])->values()->all(),
        ];
    }
}
