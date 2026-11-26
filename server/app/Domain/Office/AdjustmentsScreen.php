<?php

namespace App\Domain\Office;

use App\Domain\Inventory\ExpiryService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockAdjustmentService;
use App\Domain\Support\Actor;
use App\Filament\Resources\DirectPurchases\DirectPurchaseResource;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Support\Fields;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\User;
use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stock adjustments in the new back office (release 3, part 4): the list, one adjustment with its lines, value and
 * actions (submit, approve and post, send back, cancel, voucher), and the draft form. The query, the lines table and
 * every change are the current screen's (StockAdjustmentResource, StockAdjustmentService), so both screens keep the same
 * rules: approval above the limit by someone else, photos of losses when the company asks, disposals by a second person.
 */
final class AdjustmentsScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return StockAdjustmentResource::canViewAny();
    }

    private static function opt(array $rows): array
    {
        return collect($rows)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private function currency(): string
    {
        return $this->user->tenant?->currency_code ?? 'GHS';
    }

    /** The adjustments the search and filters pick (also for the export). */
    public function filtered(array $p): Builder
    {
        $q = StockAdjustmentResource::getEloquentQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('number', 'ilike', "%{$s}%")->orWhere('reference', 'ilike', "%{$s}%"));
        }
        foreach (['status' => StockAdjustment::STATUSES, 'kind' => StockAdjustment::KINDS, 'reason_code' => InventoryService::ADJUSTMENT_REASONS] as $k => $allowed) {
            $v = (string) ($p[$k === 'reason_code' ? 'reason' : $k] ?? '');
            if (isset($allowed[$v])) {
                $q->where($k, $v);
            }
        }
        if (filled($p['warehouse'] ?? null)) {
            $q->where('warehouse_id', (string) $p['warehouse']);
        }
        $tz = $this->user->tenant?->timezone ?: 'UTC';
        $today = CarbonImmutable::now($tz)->startOfDay();
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

    public function rowOf(StockAdjustment $a): array
    {
        return [
            'id' => $a->id, 'number' => $a->number, 'created_at' => $a->created_at?->toIso8601String(), 'warehouse' => $a->warehouse?->code,
            'reason' => InventoryService::ADJUSTMENT_REASONS[$a->reason_code] ?? $a->reason_code, 'disposal' => $a->kind === 'disposal',
            'reference' => $a->reference, 'lines' => (int) $a->lines_count, 'value' => $a->status === 'posted' ? (int) $a->value_minor : null,
            'status' => $a->status, 'status_label' => StockAdjustment::STATUSES[$a->status] ?? $a->status, 'by' => $a->created_by_name,
        ];
    }

    public function list(array $p): array
    {
        $q = $this->filtered($p);
        // select() replaces the line count the screen's query carries, which a grouped count cannot hold.
        $counts = (clone $q)->toBase()->reorder()->select('status')->selectRaw('COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));

        return [
            'currency' => $this->currency(), 'exponent' => Money::exponent($this->currency()),
            'total' => (int) $counts->sum(), 'waiting' => (int) ($counts['submitted'] ?? 0), 'drafts' => (int) ($counts['draft'] ?? 0),
            'page' => $page, 'per' => $per, 'sort' => 'created_at', 'dir' => ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
            'rows' => $this->sorted($p)->forPage($page, $per)->get()->map(fn (StockAdjustment $a) => $this->rowOf($a))->all(),
        ];
    }

    public function options(): array
    {
        return [
            'statuses' => self::opt(StockAdjustment::STATUSES), 'kinds' => self::opt(StockAdjustment::KINDS),
            'reasons' => self::opt(InventoryService::ADJUSTMENT_REASONS), 'warehouses' => self::opt(Fields::warehouses('stock.view')),
            'per' => self::PER_PAGE, 'can_create' => StockAdjustmentResource::canCreate(),
            'links' => array_filter([
                'import' => StockAdjustmentResource::canCreate() ? '/office/import?type=stock_adjustment' : null,
                // Goods with a supplier invoice and no purchase order (paid, on credit or on consignment) are not an adjustment.
                'invoice' => DirectPurchaseResource::canCreate() ? '/office/direct-purchases/new' : null,
            ]),
        ];
    }

    /** One adjustment: its header, disposal details, the lines (as posted, or as they would post now), the value and its actions. */
    public function show(StockAdjustment $a): array
    {
        $a->loadMissing('warehouse');
        $t = StockAdjustmentResource::lineRows($a);
        $draft = $a->status === 'draft';
        $open = in_array($a->status, ['draft', 'submitted'], true);
        $limit = (int) app(Settings::class)->get('stock.adjustment_approval_minor', $a->tenant_id);

        return [
            'currency' => $this->currency(), 'exponent' => Money::exponent($this->currency()),
            'id' => $a->id, 'number' => $a->number, 'status' => $a->status, 'status_label' => StockAdjustment::STATUSES[$a->status] ?? $a->status,
            'kind' => $a->kind, 'facts' => array_filter([
                'Warehouse' => "{$a->warehouse?->code} · {$a->warehouse?->name}",
                'Reason' => InventoryService::ADJUSTMENT_REASONS[$a->reason_code] ?? $a->reason_code,
                'Reference' => $a->reference ?: '—', 'Prepared by' => $a->created_by_name,
                'Submitted by' => $a->submitted_by_name ?: null,
                'Approved and posted by' => $a->posted_by_name ? $a->posted_by_name.' · '.$a->posted_at?->timezone($this->user->tenant?->timezone ?: 'UTC')->format('j M Y H:i') : null,
            ]),
            'note' => $a->note,
            'disposal' => $a->kind === 'disposal' ? [
                'method' => ExpiryService::DISPOSAL_METHODS[$a->disposal_method] ?? $a->disposal_method, 'disposed_on' => $a->disposed_on?->toDateString(),
                'witness' => $a->witness_name, 'certificate' => $a->certificate_path ? route('disposal.certificate', $a) : null,
            ] : null,
            'lines' => ['headings' => $t['headings'], 'rows' => $t['rows'], 'numeric' => [0, 5, 6, 7]],
            'value' => (int) $t['total'], 'value_note' => $a->status === 'posted' ? 'Value of the change at average cost' : 'If posted now (against stock on hand now), value at average cost',
            // Above the limit (by the gross value, as posting checks it) a second person must approve.
            'over_limit' => $open && $a->kind !== 'disposal' && (int) app(StockAdjustmentService::class)->preview($a)['gross'] > $limit ? Money::display($limit, $this->currency()) : null,
            'photos' => StockAdjustmentLine::where('stock_adjustment_id', $a->id)->whereNotNull('photo_path')->orderBy('line_no')->get(['id', 'line_no'])
                ->map(fn ($l) => ['line' => $l->line_no, 'url' => route('stock-photo', $l->id)])->all(),
            'actions' => [
                'edit' => StockAdjustmentResource::canEdit($a), 'submit' => $draft, 'post' => $open ? ($a->status === 'submitted' ? 'Approve and post' : 'Post') : null,
                'reopen' => $a->status === 'submitted' && $a->kind !== 'disposal', 'cancel' => $open,
                'import' => $draft ? '/office/import?'.http_build_query(['type' => 'stock_adjustment', 'context' => $a->id]) : null,
                'disposal_record' => $a->kind === 'disposal' ? route('print.disposal', $a) : null,
            ],
        ];
    }

    /** The draft form: a new adjustment (null) or a draft, with its lines as the form holds them. */
    public function form(?StockAdjustment $a): array
    {
        $photos = app(Settings::class)->get('stock.adjustment_photos', $this->user->tenant_id);
        $exp = Money::exponent($this->currency());
        $lines = $a ? $a->lines()->with('product', 'unit')->orderBy('line_no')->get()->map(fn ($l) => [
            'product_uom_id' => $l->product_uom_id, 'label' => $l->unit ? Fields::unitLabel($l->unit) : null, 'mode' => $l->mode,
            'qty' => ProductsScreen::qty((string) $l->qty), 'unit_cost' => $l->unit_cost_minor === null ? '' : number_format($l->unit_cost_minor / 10 ** $exp, $exp, '.', ''),
            'batch_no' => $l->batch_no ?? '', 'expires_on' => $l->expires_on?->toDateString() ?? '', 'reason_code' => $l->reason_code ?? '', 'note' => $l->note ?? '',
            'serials' => array_values((array) ($l->serials ?? [])), 'photo_path' => $l->photo_path ?? '', 'photo_url' => $l->photo_path ? route('stock-photo', $l->id) : null,
            'batches' => (bool) $l->product?->track_batches, 'serialised' => (bool) $l->product?->track_serials,
        ])->all() : [];

        return [
            'id' => $a?->id, 'number' => $a?->number, 'currency' => $this->currency(), 'exponent' => $exp,
            'values' => ['warehouse_id' => $a?->warehouse_id ?? (count($w = Fields::warehouses('stock.adjust')) === 1 ? (string) array_key_first($w) : ''),
                'reason_code' => $a?->reason_code ?? 'correction', 'reference' => $a?->reference ?? '', 'note' => $a?->note ?? '', 'lines' => $lines],
            'choices' => ['warehouses' => self::opt(Fields::warehouses('stock.adjust')), 'reasons' => self::opt(InventoryService::ADJUSTMENT_REASONS),
                'modes' => self::opt(StockAdjustment::MODES)],
            'photos' => $photos !== 'off', 'photos_required' => $photos === 'losses',
            'approval_limit' => (int) app(Settings::class)->get('stock.adjustment_approval_minor', $this->user->tenant_id),
        ];
    }

    /** Save the draft through the current screen's service (header and every line again, in order). */
    public function save(Actor $actor, StockAdjustment $a, array $v): StockAdjustment
    {
        $lines = array_map(fn ($l) => array_intersect_key((array) $l, array_flip(['product_uom_id', 'mode', 'qty', 'unit_cost', 'batch_no', 'expires_on', 'reason_code', 'note', 'serials', 'photo_path']))
            + ['serials' => []], (array) ($v['lines'] ?? []));
        $data = ['reason_code' => (string) ($v['reason_code'] ?? ''), 'reference' => trim((string) ($v['reference'] ?? '')) ?: null, 'note' => trim((string) ($v['note'] ?? '')) ?: null,
            'lines' => $lines] + ($a->exists ? [] : ['warehouse_id' => (string) ($v['warehouse_id'] ?? '')]);

        return app(StockAdjustmentService::class)->saveDraft($actor, $a, $data);
    }
}
