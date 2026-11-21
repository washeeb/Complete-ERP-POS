<?php

namespace App\Domain\Office;

use App\Domain\Purchasing\AgreementService;
use App\Domain\Purchasing\OrderSender;
use App\Domain\Purchasing\PurchaseRequestService;
use App\Domain\Purchasing\RfqService;
use App\Domain\Purchasing\SupplierMessages;
use App\Filament\Resources\PurchaseAgreements\PurchaseAgreementResource;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Resources\Rfqs\RfqResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Models\Department;
use App\Models\ProductUom;
use App\Models\PurchaseAgreement;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Rfq;
use App\Models\RfqSupplier;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Money;
use App\Support\Qty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The rest of Purchasing in the new back office (part 18 of the rewrite), as the current pages: Purchase requests (what
 * a store needs; a buyer orders it, asks for quotes or rejects it), Requests for quotation (one list to several
 * suppliers, sealed prices, compared side by side and awarded into draft purchase orders) and Contracts and blanket
 * orders (agreed prices for a period, drawn down by orders). Same queries (the resources'), same rules:
 * PurchaseRequestService, RfqService and AgreementService (ProcurementController).
 */
final class ProcurementScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public function __construct(private readonly User $user) {}

    /** @param  array<string, string>  $map */
    public static function choices(array $map): array
    {
        return collect($map)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private static function page(array $p): array
    {
        return [in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25, max(1, (int) ($p['page'] ?? 1))];
    }

    private function currency(): string
    {
        return $this->user->tenant?->currency_code ?? 'GHS';
    }

    private function m(): array
    {
        $c = $this->currency();

        return ['currency' => $c, 'exponent' => Money::exponent($c)];
    }

    private static function units(iterable $ids): Collection
    {
        return ProductUom::with('product', 'uom')->whereIn('id', collect($ids)->filter())->get()->keyBy('id');
    }

    /** What a store holds of a unit now ("In stock now"), for a store the person may buy or ask for. */
    public function available(string $unitId, string $warehouseId): ?string
    {
        if (! array_key_exists($warehouseId, Fields::warehouses(['purchasing.request', 'purchasing.manage', 'purchasing.view']))) {
            return null;
        }
        $s = Fields::stockOf($unitId, $warehouseId);

        return $s ? Fields::qty((string) round($s['qty'], 3), $s['unit']) : null;
    }

    // -------------------------------------------------------------------------------------------- Purchase requests

    public static function canRequests(): bool
    {
        return PurchaseRequestResource::canViewAny();
    }

    public function requestsQuery(): Builder
    {
        return PurchaseRequestResource::getEloquentQuery();
    }

    public const REQUEST_TONE = ['requested' => 'warning', 'quoting' => 'informative', 'ordered' => 'success', 'rejected' => 'danger', 'cancelled' => 'subtle'];

    /** @param  array<string, mixed>  $p  q (number), status, page, per */
    public function requests(array $p): array
    {
        $q = $this->requestsQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where('purchase_requests.number', 'ilike', "%{$s}%");
        }
        if (array_key_exists($p['status'] ?? '', PurchaseRequest::STATUSES)) {
            $q->where('purchase_requests.status', $p['status']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $waiting = (clone $q)->reorder()->where('purchase_requests.status', 'requested')->count();

        return ['total' => $total, 'waiting' => $waiting, 'page' => $page, 'per' => $per, 'statuses' => self::choices(PurchaseRequest::STATUSES),
            'can_create' => PurchaseRequestResource::canCreate(),
            'rows' => $q->orderByDesc('purchase_requests.created_at')->forPage($page, $per)->get()->map(fn (PurchaseRequest $r) => ['id' => $r->id, 'number' => $r->number,
                'asked_on' => $r->created_at?->toDateString(), 'store' => $r->warehouse?->name, 'lines' => (int) $r->lines_count, 'needed_by' => $r->needed_by?->toDateString(),
                'by' => $r->requested_by_name, 'status' => $r->status, 'status_label' => PurchaseRequest::STATUSES[$r->status] ?? $r->status,
                'tone' => self::REQUEST_TONE[$r->status] ?? 'informative'])->all()];
    }

    public function request(PurchaseRequest $r): array
    {
        $r->loadMissing(['lines', 'warehouse', 'purchaseOrder']);
        $manage = $r->status === 'requested' && Erp::can('purchasing.manage', $r->warehouse);
        $rfq = Rfq::where('purchase_request_id', $r->id)->latest()->first();

        return $this->m() + ['id' => $r->id, 'number' => $r->number, 'status' => $r->status, 'status_label' => PurchaseRequest::STATUSES[$r->status] ?? $r->status,
            'tone' => self::REQUEST_TONE[$r->status] ?? 'informative', 'store' => $r->warehouse?->name, 'needed_by' => $r->needed_by?->toDateString(),
            'by' => $r->requested_by_name, 'asked_at' => $r->created_at?->toIso8601String(), 'closed_reason' => $r->closed_reason, 'note' => $r->note,
            'order' => $r->purchase_order_id ? ['number' => $r->purchaseOrder?->number, 'open' => "/office/purchase-orders/{$r->purchase_order_id}"] : null,
            'rfq' => $rfq ? ['number' => $rfq->number, 'open' => "/office/rfqs/{$rfq->id}"] : null,
            'budget' => array_map(fn ($b) => ['account' => $b['account']->label(), 'budget' => (int) $b['budget'], 'spent' => (int) $b['spent'], 'committed' => (int) $b['committed'],
                'this' => (int) $b['this'], 'left' => (int) $b['left']], app(PurchaseRequestService::class)->budget($r)),
            'lines' => $r->lines->map(fn ($l) => ['no' => $l->line_no, 'sku' => $l->sku, 'name' => $l->name, 'qty' => Fields::qty((string) $l->qty, $l->uom_code), 'note' => $l->note])->all(),
            'actions' => [
                'order' => $manage ? ['suppliers' => self::choices(Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())] : null,
                'quotes' => $manage && $r->lines->every(fn ($l) => (bool) $l->product_uom_id)
                    ? ['suppliers' => self::choices(RfqResource::supplierOptions()), 'closes_at' => now()->addDays(3)->setTime(17, 0)->format('Y-m-d\TH:i')] : null,
                'reject' => $manage, 'cancel' => $r->status === 'requested' && $r->requested_by === $this->user->id]];
    }

    /** The request form: the stores, departments and kinds of spending offered. */
    public function requestForm(): array
    {
        $w = Fields::warehouses('purchasing.request');

        return ['warehouses' => self::choices($w), 'warehouse_id' => (string) (array_key_first($w) ?? ''),
            'departments' => self::choices(Department::whereNull('archived_at')->orderBy('name')->pluck('name', 'id')->all()),
            'accounts' => self::choices(FinanceUi::accounts(false, ['expense', 'asset'])), 'today' => now()->toDateString()];
    }

    // -------------------------------------------------------------------------------------------- Requests for quotation

    public static function canRfqs(): bool
    {
        return RfqResource::canViewAny();
    }

    public function rfqsQuery(): Builder
    {
        return RfqResource::getEloquentQuery();
    }

    /** The label and tone of a request for quotation, as the current list ("Ready to compare" once the closing time passed). */
    private static function rfqState(Rfq $r): array
    {
        $ready = $r->status === 'open' && ! $r->acceptsQuotes();

        return [$ready ? 'Closing time passed: compare and award' : (Rfq::STATUSES[$r->status] ?? $r->status),
            match (true) {
                $r->status === 'open' && $r->acceptsQuotes() => 'informative', in_array($r->status, ['open', 'closed'], true) => 'warning', $r->status === 'awarded' => 'success', default => 'subtle'
            }];
    }

    /** @param  array<string, mixed>  $p  q (number or title), status, page, per */
    public function rfqs(array $p): array
    {
        $q = $this->rfqsQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('rfqs.number', 'ilike', "%{$s}%")->orWhere('rfqs.title', 'ilike', "%{$s}%"));
        }
        if (array_key_exists($p['status'] ?? '', Rfq::STATUSES)) {
            $q->where('rfqs.status', $p['status']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();

        return ['total' => $total, 'page' => $page, 'per' => $per, 'statuses' => self::choices(Rfq::STATUSES), 'can_create' => RfqResource::canCreate(),
            'rows' => $q->orderByDesc('rfqs.created_at')->forPage($page, $per)->get()->map(function (Rfq $r) {
                [$label, $tone] = self::rfqState($r);

                return ['id' => $r->id, 'number' => $r->number, 'title' => $r->title, 'deliver_to' => $r->warehouse?->name, 'items' => (int) $r->lines_count,
                    'quotes' => "{$r->quoted_count} of {$r->invitations_count}", 'closes_at' => $r->closes_at?->toIso8601String(), 'status' => $r->status, 'status_label' => $label, 'tone' => $tone];
            })->all()];
    }

    public function rfq(Rfq $r): array
    {
        $r->loadMissing(['lines', 'warehouse', 'purchaseRequest']);
        [$label, $tone] = self::rfqState($r);
        $manage = Erp::can('purchasing.manage', $r->warehouse);
        $open = $r->acceptsQuotes() && $manage;
        $unsealed = $r->isUnsealed();
        $invitations = $r->invitations()->with('supplier')->get();
        $quoted = $invitations->where('status', 'quoted')->isNotEmpty();
        $cmp = $unsealed && $quoted ? app(RfqService::class)->compare($r) : null;
        $money = fn (?int $v) => $v === null ? null : (int) $v;
        $poNumbers = PurchaseOrder::whereIn('id', $invitations->pluck('purchase_order_id')->filter())->pluck('number', 'id');

        return $this->m() + ['id' => $r->id, 'number' => $r->number, 'title' => $r->title, 'status' => $r->status, 'status_label' => $label, 'tone' => $tone,
            'deliver_to' => $r->warehouse?->name, 'closes_at' => $r->closes_at?->toIso8601String(), 'needed_by' => $r->needed_by?->toDateString(), 'made_by' => $r->created_by_name,
            'request' => $r->purchase_request_id ? ['number' => $r->purchaseRequest?->number, 'open' => "/office/purchase-requests/{$r->purchase_request_id}"] : null,
            'awarded' => $r->awarded_by_name ? $r->awarded_by_name.($r->awarded_at ? ', '.$r->awarded_at->format('j M Y H:i') : '') : null, 'award_note' => $r->award_note,
            'cancelled_reason' => $r->status === 'cancelled' ? $r->cancelled_reason : null, 'terms' => $r->terms, 'unsealed' => $unsealed,
            'lines' => $r->lines->map(fn ($l) => ['id' => $l->id, 'no' => $l->line_no, 'item' => "{$l->sku} · {$l->name}", 'qty' => Fields::qty((string) $l->qty, $l->uom_code),
                'plain_qty' => Fields::qty((string) $l->qty), 'note' => $l->note])->all(),
            'invitations' => $invitations->map(fn (RfqSupplier $i) => ['id' => $i->id, 'supplier' => $i->supplier?->name, 'status' => $i->status,
                'status_label' => RfqSupplier::STATUSES[$i->status] ?? $i->status,
                'sent' => $i->sent_at ? $i->sent_at->format('j M H:i').' · '.(OrderSender::CHANNELS[$i->sent_via] ?? $i->sent_via) : null,
                'seen' => $i->viewed_at?->format('j M H:i'),
                'answer' => match ($i->status) {
                    'quoted' => 'Quoted '.$i->quoted_at?->format('j M H:i').($i->quoted_by_name ? " ({$i->quoted_by_name})" : ''), 'declined' => 'Declined'.($i->note ? ": {$i->note}" : ''), default => null,
                },
                'total' => $unsealed && $i->status === 'quoted' ? $money($i->total_minor) : null, 'order' => $unsealed && $i->purchase_order_id ? ['number' => $poNumbers[$i->purchase_order_id] ?? null,
                    'open' => "/office/purchase-orders/{$i->purchase_order_id}"] : null,
                'email' => $i->supplier?->email, 'phone' => $i->supplier?->phone, 'link' => $open ? $i->link() : null])->values()->all(),
            'comparison' => $cmp ? $this->comparison($cmp) : null,
            'actions' => [
                'send' => $open ? ['channels' => self::choices(app(SupplierMessages::class)->channels($r->tenant_id))] : null,
                'links' => $open, 'invite' => $open ? ['suppliers' => self::choices(RfqResource::supplierOptions())] : null, 'enter_quote' => $open,
                'close' => $r->status === 'open' && $manage ? ['early' => $r->closes_at->isFuture()] : null,
                'award' => in_array($r->status, ['open', 'closed'], true) && $unsealed && $manage && $quoted && $cmp ? $this->awardForm($cmp) : null,
                'cancel' => in_array($r->status, ['open', 'closed'], true) && $manage]];
    }

    /** The side-by-side comparison: each line's price from each supplier (★ cheapest, ✔ awarded), then totals, terms and the score. */
    private function comparison(array $cmp): array
    {
        $ids = array_keys($cmp['offers']);
        $money = fn (int $v) => Money::display($v, $this->currency());

        return ['suppliers' => array_map(fn ($id) => ['id' => $id, 'name' => $cmp['offers'][$id]['name'], 'best' => $cmp['best'] === $id], $ids),
            'lines' => array_values(array_map(fn ($l) => ['item' => "{$l['line']->name} (".Fields::qty((string) $l['line']->qty, $l['line']->uom_code).')',
                'cells' => array_map(function ($id) use ($l, $money) {
                    $q = $l['quotes'][$id] ?? null;

                    return $q ? $money((int) $q->unit_cost_minor).($l['cheapest'] === $id ? ' ★' : '')
                        .(Qty::toMilli((string) $q->qty) < Qty::toMilli((string) $l['line']->qty) ? ' (only '.Fields::qty((string) $q->qty).')' : '').($q->awarded ? ' ✔' : '') : '—';
                }, $ids)], $cmp['lines'])),
            'rows' => array_map(fn ($row) => ['label' => $row[0], 'cells' => array_map(fn ($id) => (string) $row[1]($cmp['offers'][$id]), $ids)], [
                ['Total before VAT', fn ($x) => $money((int) $x['invitation']->total_minor)],
                ['Delivery (days)', fn ($x) => $x['invitation']->delivery_days ?? '—'],
                ['Credit (days)', fn ($x) => $x['invitation']->payment_days ?? '—'],
                ['Prices hold until', fn ($x) => ($x['invitation']->valid_until?->format('j M Y') ?? '—').($x['expired'] ? ' (expired)' : '')],
                ['Past performance', fn ($x) => $x['record'] === null ? 'New' : "{$x['record']} ({$x['grade']})"],
                ['Their note', fn ($x) => $x['invitation']->note ?? ''],
                ['Score', fn ($x) => $x['score'].' = '.implode(' + ', array_map(fn ($p) => (string) round($p), $x['parts'])).($cmp['best'] === $x['invitation']->id ? ' · best' : '')],
            ])];
    }

    /** Award: each line, who may supply it (with price and score), and the suggestion (the best-scored supplier when they quoted it, else the cheapest). */
    private function awardForm(array $cmp): array
    {
        $money = fn (int $v) => Money::display($v, $this->currency());

        return ['lines' => array_values(array_map(fn ($lineId) => ['id' => $lineId, 'item' => "{$cmp['lines'][$lineId]['line']->name} (".Fields::qty((string) $cmp['lines'][$lineId]['line']->qty, $cmp['lines'][$lineId]['line']->uom_code).')',
            'suggested' => isset($cmp['lines'][$lineId]['quotes'][$cmp['best']]) ? $cmp['best'] : $cmp['lines'][$lineId]['cheapest'],
            'options' => collect($cmp['lines'][$lineId]['quotes'])->map(fn ($q, $id) => ['id' => (string) $id, 'label' => $cmp['offers'][$id]['name'].' · '.$money((int) $q->unit_cost_minor)
                .($cmp['lines'][$lineId]['cheapest'] === $id ? ' ★ cheapest' : '').' · score '.$cmp['offers'][$id]['score']])->values()->all()], array_keys($cmp['lines'])))];
    }

    public function rfqForm(): array
    {
        $w = Fields::warehouses('purchasing.manage');

        return ['warehouses' => self::choices($w), 'warehouse_id' => (string) (array_key_first($w) ?? ''), 'suppliers' => self::choices(RfqResource::supplierOptions()),
            'closes_at' => now()->addDays(3)->setTime(17, 0)->format('Y-m-d\TH:i'), 'today' => now()->toDateString()];
    }

    // -------------------------------------------------------------------------------------------- Contracts and blanket orders

    public static function canAgreements(): bool
    {
        return PurchaseAgreementResource::canViewAny();
    }

    public function agreementsQuery(): Builder
    {
        return PurchaseAgreementResource::getEloquentQuery()->with('supplier');
    }

    private static function agreementTone(PurchaseAgreement $a): string
    {
        return $a->inForce() ? 'success' : ($a->status === 'draft' ? 'warning' : 'subtle');
    }

    /** @param  array<string, mixed>  $p  q (number, title or supplier), status, supplier, page, per */
    public function agreements(array $p): array
    {
        $q = $this->agreementsQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('purchase_agreements.number', 'ilike', "%{$s}%")->orWhere('purchase_agreements.title', 'ilike', "%{$s}%")
                ->orWhereHas('supplier', fn ($x) => $x->where('name', 'ilike', "%{$s}%")));
        }
        if (array_key_exists($p['status'] ?? '', PurchaseAgreement::STATUSES)) {
            $q->where('purchase_agreements.status', $p['status']);
        }
        if (filled($p['supplier'] ?? null)) {
            $q->where('purchase_agreements.supplier_id', (string) $p['supplier']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();

        return ['total' => $total, 'page' => $page, 'per' => $per, 'statuses' => self::choices(PurchaseAgreement::STATUSES),
            'suppliers' => self::choices(Supplier::orderBy('name')->pluck('name', 'id')->all()), 'can_create' => PurchaseAgreementResource::canCreate(),
            'rows' => $q->orderBy('purchase_agreements.ends_on')->forPage($page, $per)->get()->map(fn (PurchaseAgreement $a) => ['id' => $a->id, 'number' => $a->number,
                'supplier' => $a->supplier?->name, 'title' => $a->title, 'kind' => $a->kind === 'blanket' ? 'Blanket order' : 'Contract', 'ends_on' => $a->ends_on?->toDateString(),
                'status_label' => $a->statusLabel(), 'tone' => self::agreementTone($a)])->all()];
    }

    public function agreement(PurchaseAgreement $a): array
    {
        $a->loadMissing(['lines', 'supplier']);
        $usage = app(AgreementService::class)->usage($a);
        $cur = $a->currency_code;
        $orders = PurchaseOrder::whereIn('id', DB::table('purchase_order_lines')->whereIn('agreement_line_id', $a->lines->pluck('id'))->select('purchase_order_id'))
            ->orderByDesc('created_at')->get();

        return ['currency' => $cur, 'exponent' => Money::exponent($cur), 'id' => $a->id, 'number' => $a->number, 'status' => $a->status, 'status_label' => $a->statusLabel(),
            'tone' => self::agreementTone($a), 'supplier' => $a->supplier?->name, 'supplier_open' => SuppliersScreen::canView() ? "/office/suppliers/{$a->supplier_id}" : null,
            'kind' => PurchaseAgreement::KINDS[$a->kind] ?? $a->kind, 'title' => $a->title, 'starts_on' => $a->starts_on?->toDateString(), 'ends_on' => $a->ends_on?->toDateString(),
            'used' => (int) $usage['value'], 'cap' => $a->value_cap_minor !== null ? (int) $a->value_cap_minor : null, 'made_by' => $a->created_by_name, 'approved_by' => $a->approved_by_name,
            'closed_reason' => $a->status === 'closed' ? $a->closed_reason : null, 'terms' => $a->terms,
            'file' => $a->file_path ? ['name' => $a->file_name ?: 'Signed agreement', 'open' => route('agreement.file', $a->id, false)] : null,
            'lines' => $a->lines->map(fn ($l) => ['no' => $l->line_no, 'item' => $l->name.($l->note ? " ({$l->note})" : ''), 'price' => (int) $l->unit_cost_minor, 'uom' => $l->uom_code,
                'ordered' => Fields::qty(Qty::format($usage['lines'][$l->id] ?? 0), $l->uom_code),
                'left' => $l->max_qty === null ? 'No limit' : Fields::qty(Qty::format(max(0, Qty::toMilli((string) $l->max_qty) - ($usage['lines'][$l->id] ?? 0))), $l->uom_code)])->all(),
            'orders' => $orders->map(fn (PurchaseOrder $p) => ['id' => $p->id, 'number' => $p->number, 'status' => PurchaseOrder::STATUSES[$p->status] ?? $p->status,
                'total' => (int) $p->total_minor, 'open' => "/office/purchase-orders/{$p->id}"])->all(),
            'actions' => [
                'edit' => PurchaseAgreementResource::canEdit($a),
                'activate' => $a->status === 'draft' && Erp::canAnywhere('purchasing.approve'),
                'release' => $a->inForce() && Erp::canAnywhere('purchasing.manage') ? ['warehouses' => self::choices(Fields::warehouses('purchasing.manage')),
                    'lines' => $a->lines->map(fn ($l) => ['id' => $l->id, 'item' => "{$l->name} · ".Money::display($l->unit_cost_minor, $cur)." / {$l->uom_code}"])->all()] : null,
                'close' => $a->status !== 'closed' && Erp::canAnywhere('purchasing.manage')]];
    }

    /** The agreement form: its values (as the current form), and the lists offered. */
    public function agreementForm(?PurchaseAgreement $a): array
    {
        $a?->loadMissing('lines');
        $units = $a ? self::units($a->lines->pluck('product_uom_id')) : collect();
        $values = $a ? [
            'supplier_id' => (string) $a->supplier_id, 'kind' => $a->kind, 'title' => $a->title, 'starts_on' => $a->starts_on?->toDateString() ?? '',
            'ends_on' => $a->ends_on?->toDateString() ?? '', 'value_cap' => $a->value_cap_minor === null ? '' : Money::format($a->value_cap_minor, $a->currency_code), 'terms' => (string) ($a->terms ?? ''),
            'file_name' => $a->file_name,
            'lines' => $a->lines->map(fn ($l) => ['kind' => $l->product_uom_id ? 'stock' : 'service', 'product_uom_id' => (string) ($l->product_uom_id ?? ''),
                'label' => $l->product_uom_id && ($u = $units->get($l->product_uom_id)) ? Fields::unitLabel($u, true) : null, 'account_id' => (string) ($l->account_id ?? ''),
                'description' => $l->product_uom_id ? '' : $l->name, 'uom_code' => $l->uom_code ?? 'EA', 'unit_cost' => Money::format($l->unit_cost_minor, $a->currency_code),
                'max_qty' => $l->max_qty === null ? '' : Fields::qty($l->max_qty), 'note' => (string) ($l->note ?? '')])->values()->all(),
        ] : ['supplier_id' => '', 'kind' => 'contract', 'title' => '', 'starts_on' => now()->toDateString(), 'ends_on' => now()->addYear()->subDay()->toDateString(),
            'value_cap' => '', 'terms' => '', 'file_name' => null, 'lines' => []];

        return ['id' => $a?->id, 'number' => $a?->number, 'values' => $values, 'currency' => $a?->currency_code ?? $this->currency(),
            'suppliers' => self::choices(Supplier::where(fn ($q) => $q->where('is_active', true)->when($a, fn ($x) => $x->orWhere('id', $a->supplier_id)))->orderBy('name')->pluck('name', 'id')->all()),
            'kinds' => self::choices(PurchaseAgreement::KINDS), 'accounts' => self::choices(FinanceUi::accounts(false, ['expense', 'asset']))];
    }
}
