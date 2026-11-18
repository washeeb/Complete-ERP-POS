<?php

namespace App\Domain\Office;

use App\Domain\Ai\AiGateway;
use App\Domain\Projects\Projects;
use App\Domain\Purchasing\OrderSender;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Resources\PurchaseAgreements\PurchaseAgreementResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Models\Department;
use App\Models\ProductUom;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Money;
use App\Support\Qty;
use Illuminate\Database\Eloquent\Builder;

/**
 * Purchase orders in the new back office (release 5 of the rewrite): the list with the current screen's search, filters
 * (status, supplier, warehouse) and columns, one order on a page of its own with every button of the current page
 * (submit, approve, send back, send to the supplier, order again, confirm delivered, receive goods, close short,
 * cancel, print), the draft form with product and service lines, and "Order from a file". Same query
 * (PurchaseOrderResource: the branches the person may see), same rules: PurchaseOrderService, OrderSender,
 * GoodsReceiptService and OrderImporter (PurchaseOrdersController).
 */
final class PurchaseOrdersScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const SORTS = ['created_at', 'number', 'expected_on', 'total_minor'];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return PurchaseOrderResource::canViewAny();
    }

    public static function canCreate(): bool
    {
        return PurchaseOrderResource::canCreate();
    }

    public function filtered(array $p): Builder
    {
        $q = PurchaseOrderResource::getEloquentQuery();
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn (Builder $w) => $w->where('purchase_orders.number', 'ilike', "%{$search}%")
                ->orWhereHas('supplier', fn (Builder $s) => $s->where('name', 'ilike', "%{$search}%")));
        }
        $statuses = array_values(array_intersect((array) ($p['status'] ?? []), array_keys(PurchaseOrder::STATUSES)));
        if ($statuses) {
            $q->whereIn('purchase_orders.status', $statuses);
        }
        if (filled($p['supplier'] ?? null)) {
            $q->where('purchase_orders.supplier_id', (string) $p['supplier']);
        }
        if (filled($p['warehouse'] ?? null)) {
            $q->where('purchase_orders.warehouse_id', (string) $p['warehouse']);
        }

        return $q;
    }

    /** @return array{0: string, 1: string} */
    public function order(array $p): array
    {
        return [in_array($p['sort'] ?? null, self::SORTS, true) ? $p['sort'] : 'created_at', ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc'];
    }

    public function sorted(array $p): Builder
    {
        [$sort, $dir] = $this->order($p);

        return $this->filtered($p)->orderBy("purchase_orders.{$sort}", $dir)->orderBy('purchase_orders.id', $dir);
    }

    public function rowOf(PurchaseOrder $po): array
    {
        return [
            'id' => $po->id, 'number' => $po->number, 'supplier' => $po->supplier?->name, 'to' => $po->warehouse?->code, 'currency' => $po->currency_code,
            'total' => (int) $po->total_minor, 'expected_on' => $po->expected_on?->toDateString(), 'status' => $po->status,
            'created_at' => $po->created_at?->toIso8601String(),
        ];
    }

    /** @param  array<string, mixed>  $p  q, status[], supplier, warehouse, sort, dir, page, per */
    public function list(array $p): array
    {
        $t = (clone $this->filtered($p))->toBase()->reorder()->selectRaw("COUNT(*) AS n, COALESCE(SUM(total_minor) FILTER (WHERE status NOT IN ('cancelled', 'draft')), 0) AS total, "
            ."COUNT(*) FILTER (WHERE status = 'pending_approval') AS waiting, COUNT(*) FILTER (WHERE status IN ('approved', 'partially_received')) AS open")->first();
        [$sort, $dir] = $this->order($p);
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $currency = $this->currency();

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency),
            'total' => (int) $t->n, 'sum' => (int) $t->total, 'waiting' => (int) $t->waiting, 'open' => (int) $t->open,
            'page' => $page, 'per' => $per, 'sort' => $sort, 'dir' => $dir,
            'rows' => $this->sorted($p)->forPage($page, $per)->get()->map(fn (PurchaseOrder $po) => $this->rowOf($po))->all(),
        ];
    }

    public function options(): array
    {
        $ai = app(AiGateway::class)->unavailableReason($this->user->tenant_id, 'vision');

        return [
            'statuses' => self::choices(PurchaseOrder::STATUSES),
            'suppliers' => self::choices(Supplier::orderBy('name')->pluck('name', 'id')->all()),
            'warehouses' => self::choices(Warehouse::orderBy('code')->get()->mapWithKeys(fn ($w) => [$w->id => "{$w->code} · {$w->name}"])->all()),
            'per' => self::PER_PAGE, 'can_create' => self::canCreate(),
            'import' => self::canCreate() ? [
                'suppliers' => self::choices(Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
                'warehouses' => self::choices(Fields::warehouses('purchasing.manage')), 'ai_unavailable' => $ai,
            ] : null,
        ];
    }

    /** One order: what the current page shows, and what may be done with it now (with what each dialog needs). */
    public function show(PurchaseOrder $po): array
    {
        $po->loadMissing(['lines.department', 'supplier', 'warehouse', 'department', 'agreement']);
        $cur = $po->currency_code;
        $wh = $po->warehouse;
        $manage = Erp::can('purchasing.manage', $wh);
        $approve = Erp::can('purchasing.approve', $wh) && $po->created_by !== $this->user->id; // never one's own order (the service refuses it)
        $receive = Erp::can('stock.receive', $wh);
        $receivable = $po->isReceivable();
        $left = fn ($l) => Fields::qty(Qty::format(max(0, Qty::toMilli((string) $l->qty) - Qty::toMilli((string) $l->received_qty))));
        $services = $po->lines->filter(fn ($l) => $l->product_id === null && Qty::toMilli((string) $l->received_qty) < Qty::toMilli((string) $l->qty));
        $goods = $po->lines->filter(fn ($l) => $l->product_id !== null);
        $live = in_array($po->status, ['approved', 'partially_received', 'received', 'closed'], true);

        return [
            'currency' => $cur, 'exponent' => Money::exponent($cur),
            'id' => $po->id, 'number' => $po->number, 'status' => $po->status, 'status_label' => PurchaseOrder::STATUSES[$po->status] ?? $po->status,
            'supplier' => ['id' => $po->supplier_id, 'name' => $po->supplier?->name, 'open' => SuppliersScreen::canView() ? "/office/suppliers/{$po->supplier_id}" : null],
            'warehouse' => $wh ? "{$wh->code} · {$wh->name}" : null,
            'net' => (int) $po->net_minor, 'tax' => (int) $po->tax_minor, 'total' => (int) $po->total_minor,
            'expected_on' => $po->expected_on?->toDateString(), 'created_by' => $po->created_by_name, 'created_at' => $po->created_at?->toIso8601String(),
            'approved_by' => $po->approved_by_name, 'rejected_reason' => $po->rejected_reason, 'close_reason' => $po->close_reason,
            'sent' => $live ? ($po->sent_at ? ['at' => $po->sent_at->toIso8601String(), 'via' => OrderSender::CHANNELS[$po->sent_via] ?? $po->sent_via, 'to' => $po->sent_to,
                'by' => $po->sent_by_name] : false) : null,
            'confirmed' => $live ? ($po->supplier_confirmed_at ? ['promised_on' => $po->supplier_promised_on?->toDateString(), 'at' => $po->supplier_confirmed_at->toIso8601String(),
                'reply' => $po->supplier_reply] : false) : null,
            'dispatched' => $po->dispatched_at ? ['at' => $po->dispatched_at->toIso8601String(), 'ref' => $po->dispatch_ref] : null,
            'department' => $po->department?->name,
            'agreement' => $po->agreement_id ? ['number' => $po->agreement?->number, 'open' => ProductsScreen::path(PurchaseAgreementResource::getUrl('view', ['record' => $po->agreement_id]))] : null,
            'budget_note' => $po->over_budget ? $po->budget_note : null, 'note' => $po->note,
            'lines' => $po->lines->map(fn ($l) => [
                'sku' => $l->sku, 'name' => $l->name, 'service' => $l->isNonStock(), 'department' => $l->department?->name,
                'ordered' => Fields::qty($l->qty, $l->uom_code), 'received' => Fields::qty($l->received_qty, $l->uom_code),
                'cost' => (int) $l->unit_cost_minor, 'total' => (int) $l->total_minor])->values()->all(),
            'actions' => [
                'print' => route('print.purchase-order', $po->id, false),
                'edit' => $po->status === 'draft' && $manage,
                'submit' => $po->status === 'draft' && $manage,
                'approve' => $po->status === 'pending_approval' && $approve,
                'reject' => $po->status === 'pending_approval' && $approve,
                'send' => in_array($po->status, ['approved', 'partially_received'], true) && $manage ? [
                    'again' => (bool) $po->sent_at, 'channels' => self::choices(app(OrderSender::class)->channels($po->tenant_id)),
                    'email' => $po->supplier?->email, 'phone' => $po->supplier?->phone] : null,
                'copy' => $po->status !== 'draft' && $manage,
                'confirm_delivered' => $receivable && $services->isNotEmpty() && ($receive || $manage)
                    ? ['lines' => $services->map(fn ($l) => ['id' => $l->id, 'item' => "{$l->name} ({$l->uom_code}), ordered ".Fields::qty($l->qty).', delivered so far '.Fields::qty($l->received_qty),
                        'qty' => $left($l)])->values()->all()] : null,
                'receive' => $receivable && $goods->isNotEmpty() && $receive ? [
                    'bins' => $wh?->use_bins ? self::choices(Fields::bins($wh->id)) : null,
                    'lines' => $goods->map(function ($l) use ($left) {
                        $p = $l->product;

                        return ['id' => $l->id, 'item' => "{$l->sku} · {$l->name} ({$l->uom_code})", 'batched' => (bool) $p?->track_batches,
                            'serialised' => (bool) $p?->track_serials, 'qty' => $left($l)];
                    })->values()->all()] : null,
                'close_short' => in_array($po->status, ['approved', 'partially_received'], true) && $manage,
                'cancel' => in_array($po->status, ['draft', 'pending_approval', 'approved'], true) && $manage,
                'receipts' => $po->receipts()->exists() ? ProductsScreen::path(GoodsReceiptResource::getUrl('index', ['tableSearch' => $po->number])) : null,
            ],
        ];
    }

    /** The draft form: the current form's fields, with what each list offers. */
    public function form(?PurchaseOrder $po): array
    {
        $po?->loadMissing('lines');
        $cur = $po?->currency_code ?: $this->currency();
        $units = $po ? ProductUom::with('product', 'uom')->whereIn('id', $po->lines->pluck('product_uom_id')->filter())->get()->keyBy('id') : collect();

        return [
            'id' => $po?->id, 'number' => $po?->number, 'currency' => $cur,
            'values' => [
                'supplier_id' => (string) ($po?->supplier_id ?? ''), 'warehouse_id' => (string) ($po?->warehouse_id ?? array_key_first(Fields::warehouses('purchasing.manage')) ?? ''),
                'expected_on' => $po?->expected_on?->toDateString() ?? '', 'department_id' => (string) ($po?->department_id ?? ''), 'note' => $po?->note ?? '',
                'lines' => $po ? $po->lines->map(fn ($l) => [
                    'kind' => $l->isNonStock() ? 'service' : 'stock', 'product_uom_id' => (string) ($l->product_uom_id ?? ''),
                    'label' => $l->product_uom_id && ($u = $units->get($l->product_uom_id)) ? Fields::unitLabel($u, true) : null,
                    'account_id' => (string) ($l->account_id ?? ''), 'description' => $l->isNonStock() ? $l->name : '', 'uom_code' => $l->uom_code ?? 'EA',
                    'department_id' => (string) ($l->department_id ?? ''), 'project_id' => (string) ($l->project_id ?? ''), 'agreement_line_id' => (string) ($l->agreement_line_id ?? ''),
                    'qty' => Fields::qty($l->qty), 'unit_cost' => Money::format($l->unit_cost_minor, $cur)])->values()->all() : [],
            ],
            'choices' => [
                'suppliers' => self::choices(Supplier::where(fn ($q) => $q->where('is_active', true)->when($po, fn ($x) => $x->orWhere('id', $po->supplier_id)))
                    ->orderBy('name')->pluck('name', 'id')->all()),
                'warehouses' => self::choices(Fields::warehouses('purchasing.manage')),
                'departments' => self::choices(Department::whereNull('archived_at')->orderBy('name')->pluck('name', 'id')->all()),
                'accounts' => self::choices(FinanceUi::accounts(false, ['expense', 'asset'])),
                'projects' => Projects::enabled() ? self::choices(Project::whereIn('status', ['planned', 'active', 'on_hold'])->orderBy('number')->get()
                    ->mapWithKeys(fn ($p) => [$p->id => "{$p->number} {$p->name}"])->all()) : null,
            ],
            'can_add_supplier' => Erp::canAnywhere('suppliers.manage'),
        ];
    }

    /** The form's values → PurchaseOrderService::saveDraft's data (service lines carry no product, product lines no account). */
    public function toDomain(array $v): array
    {
        $blank = fn ($x) => is_string($x) && trim($x) === '' ? null : $x;

        return [
            'supplier_id' => $blank($v['supplier_id'] ?? null), 'warehouse_id' => $blank($v['warehouse_id'] ?? null), 'expected_on' => $blank($v['expected_on'] ?? null),
            'department_id' => $blank($v['department_id'] ?? null), 'note' => $blank($v['note'] ?? null),
            'lines' => array_map(function ($l) use ($blank) {
                $service = ($l['kind'] ?? 'stock') === 'service';

                return [
                    'product_uom_id' => $service ? null : $blank($l['product_uom_id'] ?? null),
                    'account_id' => $service ? $blank($l['account_id'] ?? null) : null, 'description' => $service ? trim((string) ($l['description'] ?? '')) : null,
                    'uom_code' => $service ? (trim((string) ($l['uom_code'] ?? '')) ?: 'EA') : null,
                    'department_id' => $service ? $blank($l['department_id'] ?? null) : null, 'project_id' => $service ? $blank($l['project_id'] ?? null) : null,
                    'agreement_line_id' => $blank($l['agreement_line_id'] ?? null), 'qty' => (string) ($l['qty'] ?? ''), 'unit_cost' => (string) ($l['unit_cost'] ?? ''),
                ];
            }, array_values((array) ($v['lines'] ?? []))),
        ];
    }

    /** For a line: the supplier's pack rule and what the store holds now ("In stock there"). */
    public function lineInfo(?string $supplierId, ?string $unitId, ?string $warehouseId): array
    {
        $s = Fields::stockOf($unitId, $warehouseId);

        return ['rule' => PurchaseOrderResource::packRule($supplierId, $unitId),
            'available' => $s ? Fields::qty((string) round($s['qty'], 3), $s['unit']) : null];
    }

    /** @param  array<string, string>  $map */
    public static function choices(array $map): array
    {
        return collect($map)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private function currency(): string
    {
        return $this->user->tenant?->currency_code ?? 'GHS';
    }
}
