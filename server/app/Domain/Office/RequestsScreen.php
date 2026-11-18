<?php

namespace App\Domain\Office;

use App\Domain\Inventory\StockRequestService;
use App\Domain\Support\Actor;
use App\Filament\Resources\StockRequests\StockRequestResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\Product;
use App\Models\StockRequest;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stock requests in the new back office (release 3, part 5): the list, one request with its lines (asked for and sent),
 * the "Ask for stock" form, and approve / send / reject / cancel — through StockRequestService and the current screen's
 * query. Between two industries both sides approve before anything is sent, and the supplier names its own product for
 * each line; sending creates and dispatches an ordinary transfer, and receiving that transfer closes the request.
 */
final class RequestsScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return StockRequestResource::canViewAny();
    }

    private static function opt(array $rows): array
    {
        return collect($rows)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private function tz(): string
    {
        return $this->user->tenant?->timezone ?: 'UTC';
    }

    /**
     * Requests waiting on this person, as the current menu's badge counts them: to send (from a branch where they may
     * transfer stock), or to approve (between industries: the asking side first, then the supplying side).
     */
    public static function waitingOnMe(Builder $q): Builder
    {
        $send = Erp::branchIds('stock.transfer');
        $approve = Erp::branchIds('stock.request.approve');
        $in = fn (Builder $w, string $col, ?array $ids) => $ids === null ? $w : $w->whereIn($col, $ids);

        return $q->where(fn (Builder $w) => $w
            ->orWhere(fn (Builder $s) => $in($s->where(fn ($x) => $x->where('status', 'approved')->orWhere(fn ($y) => $y->where('status', 'requested')->where('cross_industry', false))), 'from_branch_id', $send))
            ->orWhere(fn (Builder $s) => $in($s->where('status', 'requested')->where('cross_industry', true)->whereNotNull('requester_approved_at'), 'from_branch_id', $approve))
            ->orWhere(fn (Builder $s) => $in($s->where('status', 'requested')->where('cross_industry', true)->whereNull('requester_approved_at'), 'to_branch_id', $approve)));
    }

    /** "Awaiting approval (asking side)" where the current list says so; otherwise the status. */
    public static function statusLabel(StockRequest $r): string
    {
        return match ($r->waitingFor()) {
            'requester' => 'Awaiting approval (asking side)',
            'supplier' => 'Awaiting approval (supplier)',
            default => StockRequest::STATUSES[$r->status] ?? $r->status,
        };
    }

    /** The requests the search and filters pick (also for the export). */
    public function filtered(array $p): Builder
    {
        $q = StockRequestResource::getEloquentQuery()->withCount('lines');
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where('number', 'ilike', "%{$s}%");
        }
        if (isset(StockRequest::STATUSES[$p['status'] ?? ''])) {
            $q->where('status', $p['status']);
        }
        if (filled($p['from_wh'] ?? null)) {
            $q->where('from_warehouse_id', (string) $p['from_wh']);
        }
        if (filled($p['to_wh'] ?? null)) {
            $q->where('to_warehouse_id', (string) $p['to_wh']);
        }
        if (! empty($p['cross'])) {
            $q->where('cross_industry', true);
        }
        if (! empty($p['waiting'])) {
            self::waitingOnMe($q);
        }
        $today = CarbonImmutable::now($this->tz())->startOfDay();
        $period = (string) ($p['period'] ?? '');
        if ($period === 'today') {
            $q->where('created_at', '>=', $today->utc());
        } elseif (in_array($period, ['7', '30', '90'], true)) {
            $q->where('created_at', '>=', $today->subDays((int) $period - 1)->utc());
        }

        return $q;
    }

    public function sorted(array $p): Builder
    {
        $dir = ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $this->filtered($p)->orderBy('created_at', $dir)->orderBy('id', $dir);
    }

    public function rowOf(StockRequest $r): array
    {
        return [
            'id' => $r->id, 'number' => $r->number, 'for' => $r->toWarehouse?->code, 'asked_of' => $r->fromWarehouse?->code, 'cross' => (bool) $r->cross_industry,
            'status' => $r->status, 'status_label' => self::statusLabel($r), 'lines' => (int) $r->lines_count,
            'by' => $r->requested_by_name, 'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    public function list(array $p): array
    {
        $q = $this->filtered($p);
        $counts = (clone $q)->toBase()->reorder()->select('status')->selectRaw('COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));

        return [
            'total' => (int) $counts->sum(), 'open' => (int) (($counts['requested'] ?? 0) + ($counts['approved'] ?? 0)), 'sent' => (int) ($counts['supplied'] ?? 0),
            'waiting' => self::waitingOnMe(StockRequestResource::getEloquentQuery())->count(),
            'page' => $page, 'per' => $per, 'sort' => 'created_at', 'dir' => ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
            'rows' => $this->sorted($p)->forPage($page, $per)->get()->map(fn (StockRequest $r) => $this->rowOf($r))->all(),
        ];
    }

    /** Every warehouse in use, with its industry ("G1-W1 · Grocery store (Grocery)"): anyone may be asked. */
    private function allWarehouses(): array
    {
        return Warehouse::with('branch.industry')->where('is_active', true)->orderBy('code')->get()
            ->mapWithKeys(fn ($w) => [$w->id => "{$w->code} · {$w->name}".($w->branch?->industry ? " ({$w->branch->industry->name})" : '')])->all();
    }

    public function options(): array
    {
        return ['statuses' => self::opt(StockRequest::STATUSES), 'warehouses' => self::opt($this->allWarehouses()), 'per' => self::PER_PAGE,
            'can_create' => StockRequestResource::canCreate()];
    }

    /** One request: who asked, who approved and sent it, its lines, its transfer, and what this person may do next. */
    public function show(StockRequest $r): array
    {
        $r->loadMissing('fromWarehouse.branch', 'toWarehouse', 'transfer');
        $when = fn ($at) => $at?->timezone($this->tz())->format('j M Y H:i');
        $stage = $r->waitingFor();
        $approve = match ($stage) {
            'requester' => Erp::can('stock.request.approve', $r->toWarehouse),
            'supplier' => Erp::can('stock.request.approve', $r->fromWarehouse),
            default => false,
        };
        $reject = $stage !== null && (Erp::can('stock.request.approve', $r->fromWarehouse) || Erp::can('stock.transfer', $r->fromWarehouse)
            || ($stage === 'requester' && Erp::can('stock.request.approve', $r->toWarehouse)));

        return [
            'id' => $r->id, 'number' => $r->number, 'status' => $r->status, 'status_label' => self::statusLabel($r), 'waiting_for' => $stage,
            'cross' => (bool) $r->cross_industry,
            'facts' => array_filter([
                'For' => "{$r->toWarehouse?->code} · {$r->toWarehouse?->name}", 'Asked of' => "{$r->fromWarehouse?->code} · {$r->fromWarehouse?->name}",
                'Asked by' => trim($r->requested_by_name.' · '.$when($r->created_at), ' ·'),
                'Between industries' => $r->cross_industry ? 'Yes: both sides approve, moved at cost' : 'No',
                'Approved (asking side)' => $r->cross_industry ? ($r->requester_approved_by_name ? $r->requester_approved_by_name.' · '.$when($r->requester_approved_at) : '—') : null,
                'Approved (supplying side)' => $r->cross_industry ? ($r->supplier_approved_by_name ? $r->supplier_approved_by_name.' · '.$when($r->supplier_approved_at) : '—') : null,
                'Sent by' => $r->supplied_by_name ? $r->supplied_by_name.' · '.$when($r->supplied_at) : null,
                'Reason' => in_array($r->status, ['rejected', 'cancelled'], true) ? ($r->closed_reason ?: '—') : null,
            ]),
            'note' => $r->note,
            'transfer' => $r->transfer ? ['id' => $r->transfer->id, 'number' => $r->transfer->number, 'status_label' => StockTransfer::STATUSES[$r->transfer->status] ?? $r->transfer->status] : null,
            'lines' => $r->lines()->get()->map(fn ($l) => [
                'id' => $l->id, 'sku' => $l->sku, 'name' => $l->name, 'unit' => $l->uom_code, 'qty' => ProductsScreen::qty((string) $l->qty),
                'sent' => $l->supplied_qty === null ? null : ProductsScreen::qty((string) $l->supplied_qty),
            ])->all(),
            'actions' => [
                'approve' => $approve,
                'approve_text' => $stage === 'requester' ? 'Approves this request for your branch. The supplying branch approves next.'
                    : 'Approves sending these goods to another industry, at their cost.',
                'supply' => $stage === 'supply' && Erp::can('stock.transfer', $r->fromWarehouse),
                'reject' => $reject,
                'cancel' => in_array($r->status, ['requested', 'approved'], true) && Erp::can('stock.request', $r->toWarehouse),
                'print' => route('print.stock-request', $r),
            ],
        ];
    }

    /** "Ask for stock": for which of your warehouses, and whom to ask. */
    public function form(): array
    {
        $mine = Fields::warehouses('stock.request');

        return [
            'values' => ['to_warehouse_id' => count($mine) === 1 ? (string) array_key_first($mine) : '', 'from_warehouse_id' => '', 'note' => '', 'lines' => []],
            'choices' => ['to' => self::opt($mine), 'from' => self::opt($this->allWarehouses())],
        ];
    }

    /** What both sides hold of a unit: "You have" and "They have", as the current form shows them. */
    public function stock(string $unitId, ?string $for, ?string $from): array
    {
        $show = fn ($s) => $s ? ['qty' => round($s['qty'], 3), 'unit' => $s['unit']] : null;

        return ['mine' => $show(Fields::stockOf($unitId, $for)), 'theirs' => $show(Fields::stockOf($unitId, $from))];
    }

    /** Between industries: the supplier's own stocked products matching what is typed, to send for a line. */
    public function ownProducts(StockRequest $r, string $q): array
    {
        if (mb_strlen(trim($q)) < 2) {
            return [];
        }
        $industry = $r->fromWarehouse?->branch?->industry_id;

        return Product::where('is_active', true)->where('track_stock', true)
            ->where(fn ($w) => $w->whereNull('industry_id')->when($industry, fn ($x) => $x->orWhere('industry_id', $industry)))
            ->where(fn ($w) => $w->where('name', 'ilike', '%'.trim($q).'%')->orWhere('sku', 'ilike', '%'.trim($q).'%'))
            ->orderBy('name')->limit(30)->get()->map(fn ($p) => ['id' => $p->id, 'label' => "{$p->sku} · {$p->name}"])->all();
    }

    public function request(Actor $actor, array $v): StockRequest
    {
        return app(StockRequestService::class)->request($actor, ['to_warehouse_id' => (string) ($v['to_warehouse_id'] ?? ''),
            'from_warehouse_id' => (string) ($v['from_warehouse_id'] ?? ''), 'note' => trim((string) ($v['note'] ?? '')) ?: null,
            'lines' => array_map(fn ($l) => ['product_uom_id' => (string) ($l['product_uom_id'] ?? ''), 'qty' => (string) ($l['qty'] ?? '')], (array) ($v['lines'] ?? []))]);
    }
}
