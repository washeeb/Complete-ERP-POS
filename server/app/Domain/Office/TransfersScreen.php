<?php

namespace App\Domain\Office;

use App\Domain\Inventory\TransferService;
use App\Domain\Support\Actor;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Waybill;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Transfers in the new back office (release 3, part 4): the list, one transfer with its lines (sent and received), the
 * draft form, and dispatch / receive / cancel — through TransferService and the current screen's query, so stock leaves
 * the sending warehouse at dispatch, arrives when received, and a difference on arrival goes to the review queue.
 */
final class TransfersScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return StockTransferResource::canViewAny();
    }

    private static function opt(array $rows): array
    {
        return collect($rows)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private function tz(): string
    {
        return $this->user->tenant?->timezone ?: 'UTC';
    }

    /** The transfers the search and filters pick (also for the export). */
    public function filtered(array $p): Builder
    {
        $q = StockTransferResource::getEloquentQuery()->withCount('lines');
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where('number', 'ilike', "%{$s}%");
        }
        if (isset(StockTransfer::STATUSES[$p['status'] ?? ''])) {
            $q->where('status', $p['status']);
        }
        if (filled($p['from_wh'] ?? null)) {
            $q->where('from_warehouse_id', (string) $p['from_wh']);
        }
        if (filled($p['to_wh'] ?? null)) {
            $q->where('to_warehouse_id', (string) $p['to_wh']);
        }
        if (! empty($p['difference'])) {
            $q->where('has_discrepancy', true);
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

    public function rowOf(StockTransfer $t): array
    {
        return [
            'id' => $t->id, 'number' => $t->number, 'from' => $t->fromWarehouse?->code, 'to' => $t->toWarehouse?->code, 'status' => $t->status,
            'status_label' => StockTransfer::STATUSES[$t->status] ?? $t->status, 'difference' => (bool) $t->has_discrepancy, 'lines' => (int) $t->lines_count,
            'dispatched_at' => $t->dispatched_at?->toIso8601String(), 'created_at' => $t->created_at?->toIso8601String(), 'by' => $t->created_by_name,
        ];
    }

    public function list(array $p): array
    {
        $q = $this->filtered($p);
        $counts = (clone $q)->toBase()->reorder()->select('status')->selectRaw('COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        // In transit to a warehouse of the person's: theirs to receive (the current menu's badge).
        $ids = Erp::branchIds('stock.transfer');
        $toReceive = StockTransfer::where('status', 'in_transit')->when($ids !== null, fn ($w) => $w->whereIn('to_branch_id', $ids))->count();
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));

        return [
            'total' => (int) $counts->sum(), 'in_transit' => (int) ($counts['in_transit'] ?? 0), 'to_receive' => $toReceive, 'drafts' => (int) ($counts['draft'] ?? 0),
            'page' => $page, 'per' => $per, 'sort' => 'created_at', 'dir' => ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
            'rows' => $this->sorted($p)->forPage($page, $per)->get()->map(fn (StockTransfer $t) => $this->rowOf($t))->all(),
        ];
    }

    public function options(): array
    {
        $all = Warehouse::where('is_active', true)->orderBy('code')->get()->mapWithKeys(fn ($w) => [$w->id => "{$w->code} · {$w->name}"])->all();

        return ['statuses' => self::opt(StockTransfer::STATUSES), 'warehouses' => self::opt($all), 'per' => self::PER_PAGE, 'can_create' => StockTransferResource::canCreate()];
    }

    /** One transfer: who made, dispatched and received it, the lines sent and received, and what may be done next. */
    public function show(StockTransfer $t): array
    {
        $t->loadMissing('fromWarehouse', 'toWarehouse');
        $when = fn ($at) => $at?->timezone($this->tz())->format('j M Y H:i');
        $waybill = Waybill::where('source_id', $t->id)->where('status', '<>', 'cancelled')->first();
        $sending = Erp::can('stock.transfer', $t->fromWarehouse);
        $receiving = $t->status === 'in_transit' && Erp::can('stock.transfer', $t->toWarehouse);

        return [
            'id' => $t->id, 'number' => $t->number, 'status' => $t->status, 'status_label' => StockTransfer::STATUSES[$t->status] ?? $t->status,
            'facts' => array_filter([
                'From' => "{$t->fromWarehouse?->code} · {$t->fromWarehouse?->name}", 'To' => "{$t->toWarehouse?->code} · {$t->toWarehouse?->name}",
                'Created by' => $t->created_by_name,
                'Dispatched by' => $t->dispatched_by_name ? $t->dispatched_by_name.' · '.$when($t->dispatched_at) : null,
                'Received by' => $t->received_by_name ? $t->received_by_name.' · '.$when($t->received_at) : null,
            ]),
            'note' => $t->note,
            'difference' => $t->status === 'received' ? (bool) $t->has_discrepancy : null,
            'lines' => $t->lines()->orderBy('id')->get()->map(fn ($l) => [
                'id' => $l->id, 'sku' => $l->sku, 'name' => $l->name, 'unit' => $l->uom_code, 'sent' => ProductsScreen::qty((string) $l->qty),
                'received' => $l->received_qty === null ? null : ProductsScreen::qty((string) $l->received_qty), 'serials' => array_values((array) ($l->serials ?? [])),
            ])->all(),
            'bins' => $receiving && $t->toWarehouse?->use_bins ? self::opt(Fields::bins($t->to_warehouse_id)) : [],
            'actions' => [
                'edit' => StockTransferResource::canEdit($t), 'dispatch' => $t->status === 'draft' && $sending, 'receive' => $receiving,
                'cancel' => $t->status === 'draft' && $sending, 'print' => route('print.transfer', $t),
                'waybill' => ($waybill ? '/office/waybills/'.($waybill instanceof Model ? $waybill->getKey() : $waybill) : '/office/waybills/new?'.http_build_query(['kind' => 'transfer', 'source' => $t->id])),
                'waybill_label' => $waybill ? 'Waybill' : 'Make a waybill',
            ],
        ];
    }

    /** The draft form: a new transfer (null) or a draft, with its lines. */
    public function form(?StockTransfer $t): array
    {
        $from = Fields::warehouses('stock.transfer');

        return [
            'id' => $t?->id, 'number' => $t?->number,
            'values' => ['from_warehouse_id' => $t?->from_warehouse_id ?? (count($from) === 1 ? (string) array_key_first($from) : ''), 'to_warehouse_id' => $t?->to_warehouse_id ?? '',
                'note' => $t?->note ?? '',
                'lines' => $t ? $t->lines()->with('unit', 'product')->orderBy('id')->get()->map(fn ($l) => ['product_uom_id' => $l->product_uom_id,
                    'label' => $l->unit ? Fields::unitLabel($l->unit) : "{$l->sku} · {$l->name}", 'product_id' => $l->product_id, 'qty' => ProductsScreen::qty((string) $l->qty),
                    'serials' => array_values((array) ($l->serials ?? [])), 'serialised' => (bool) $l->product?->track_serials])->all() : []],
            'choices' => ['from' => self::opt($from), 'to' => $this->options()['warehouses']],
        ];
    }

    public function save(Actor $actor, StockTransfer $t, array $v): StockTransfer
    {
        $lines = array_map(fn ($l) => ['product_uom_id' => (string) ($l['product_uom_id'] ?? ''), 'qty' => (string) ($l['qty'] ?? ''), 'serials' => array_values((array) ($l['serials'] ?? []))],
            (array) ($v['lines'] ?? []));

        return app(TransferService::class)->saveDraft($actor, $t, ['from_warehouse_id' => (string) ($v['from_warehouse_id'] ?? ''), 'to_warehouse_id' => (string) ($v['to_warehouse_id'] ?? ''),
            'note' => trim((string) ($v['note'] ?? '')) ?: null, 'lines' => $lines]);
    }
}
