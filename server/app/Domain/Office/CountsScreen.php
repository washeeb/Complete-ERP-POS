<?php

namespace App\Domain\Office;

use App\Filament\Resources\StockCounts\StockCountResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Money;
use App\Support\Qty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stock counts in the new back office (release 3, part 4): the list, starting a count, one count with its lines, and the
 * counted quantities entered, submitted, approved and posted — through StockCountService, with the current screen's query
 * and its rule that a blind count hides the expected figures from counters until it is submitted.
 */
final class CountsScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return StockCountResource::canViewAny();
    }

    private static function opt(array $rows): array
    {
        return collect($rows)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private function tz(): string
    {
        return $this->user->tenant?->timezone ?: 'UTC';
    }

    /** The counts the search and filters pick (also for the export). */
    public function filtered(array $p): Builder
    {
        $q = StockCountResource::getEloquentQuery()->withCount('lines');
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where('number', 'ilike', "%{$s}%");
        }
        if (isset(StockCount::STATUSES[$p['status'] ?? ''])) {
            $q->where('status', $p['status']);
        }
        if (filled($p['warehouse'] ?? null)) {
            $q->where('warehouse_id', (string) $p['warehouse']);
        }
        $today = CarbonImmutable::now($this->tz())->startOfDay();
        $period = (string) ($p['period'] ?? '');
        if ($period === 'today') {
            $q->where('started_at', '>=', $today->utc());
        } elseif (in_array($period, ['7', '30', '90'], true)) {
            $q->where('started_at', '>=', $today->subDays((int) $period - 1)->utc());
        }

        return $q;
    }

    public function sorted(array $p): Builder
    {
        $dir = ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $this->filtered($p)->orderBy('started_at', $dir)->orderBy('id', $dir);
    }

    public function rowOf(StockCount $c): array
    {
        return [
            'id' => $c->id, 'number' => $c->number, 'warehouse' => $c->warehouse?->code, 'bin' => $c->bin?->code, 'status' => $c->status,
            'status_label' => StockCount::STATUSES[$c->status] ?? $c->status, 'blind' => (bool) $c->blind, 'started_at' => $c->started_at?->toIso8601String(),
            'started_by' => $c->started_by_name, 'lines' => (int) $c->lines_count,
            'difference' => $c->status === 'posted' ? (int) $c->variance_value_minor : null,
        ];
    }

    public function list(array $p): array
    {
        $q = $this->filtered($p);
        $counts = (clone $q)->toBase()->reorder()->select('status')->selectRaw('COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $currency = $this->user->tenant?->currency_code ?? 'GHS';

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency),
            'total' => (int) $counts->sum(), 'counting' => (int) ($counts['counting'] ?? 0), 'waiting' => (int) ($counts['submitted'] ?? 0),
            'page' => $page, 'per' => $per, 'sort' => 'started_at', 'dir' => ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
            'rows' => $this->sorted($p)->forPage($page, $per)->get()->map(fn (StockCount $c) => $this->rowOf($c))->all(),
        ];
    }

    /** Filters, and what starting a count offers: the warehouses the person may count in, and their bins. */
    public function options(): array
    {
        $start = Erp::canAnywhere('stock.count');
        $warehouses = $start ? Fields::warehouses('stock.count') : [];
        $binned = $warehouses ? Warehouse::whereIn('id', array_keys($warehouses))->where('use_bins', true)->pluck('id')->all() : [];

        return [
            'statuses' => self::opt(StockCount::STATUSES), 'warehouses' => self::opt(Fields::warehouses(['stock.count', 'stock.count.approve'])),
            'per' => self::PER_PAGE, 'can_start' => $start, 'start_warehouses' => self::opt($warehouses),
            'bins' => collect($binned)->mapWithKeys(fn ($id) => [$id => self::opt(Fields::bins($id))])->all(),
        ];
    }

    /** One count: its header, the lines (expected and the difference only where the person may see them), its actions. */
    public function show(StockCount $c): array
    {
        $c->loadMissing('warehouse', 'bin');
        $expected = StockCountResource::showsExpected($c);
        $currency = $this->user->tenant?->currency_code ?? 'GHS';
        $count = $c->status === 'counting' && Erp::can('stock.count', $c->warehouse);
        $q = fn ($v) => $v === null ? null : ProductsScreen::qty((string) $v);
        $lines = StockCountLine::where('stock_count_id', $c->id)->with('product:id,sku,name,base_uom_id', 'product.baseUom:id,code', 'bin:id,code')
            ->orderBy('id')->get(); // UUIDv7: the order they were counted in

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency),
            'id' => $c->id, 'number' => $c->number, 'status' => $c->status, 'status_label' => StockCount::STATUSES[$c->status] ?? $c->status, 'blind' => (bool) $c->blind,
            'facts' => array_filter([
                'Warehouse' => "{$c->warehouse?->code} · {$c->warehouse?->name}", 'Counting' => $c->bin ? "Bin {$c->bin->code}" : 'Whole warehouse',
                'Type' => $c->blind ? 'Blind' : 'Expected figures shown',
                'Started by' => $c->started_by_name.' · '.$c->started_at?->timezone($this->tz())->format('j M Y H:i'),
                'Submitted by' => $c->submitted_by_name ?: null, 'Posted by' => $c->posted_by_name ?: null,
                'Difference value' => $c->status === 'posted' ? Money::display((int) $c->variance_value_minor, $currency) : null,
            ]),
            'note' => $c->note,
            'shows_expected' => $expected,
            'lines' => $lines->map(fn (StockCountLine $l) => [
                'sku' => $l->product?->sku, 'product' => $l->product?->name, 'unit' => $l->product?->baseUom?->code, 'bin' => $l->bin?->code,
                'counted' => $q($l->counted_qty), 'by' => $l->counted_by_name, 'expected' => $expected ? $q($l->expected_qty) : null,
                'difference' => $expected && $l->counted_qty !== null
                    ? ProductsScreen::qty(Qty::format(Qty::toMilli((string) $l->counted_qty) - Qty::toMilli((string) $l->expected_qty))) : null,
            ])->all(),
            'counted' => $lines->whereNotNull('counted_qty')->count(),
            'bins' => $count && $c->warehouse?->use_bins && ! $c->bin_id ? self::opt(Fields::bins($c->warehouse_id)) : [],
            'actions' => [
                'count' => $count, 'submit' => $count,
                'post' => $c->status === 'submitted' && Erp::can('stock.count.approve', $c->warehouse),
                'cancel' => in_array($c->status, ['counting', 'submitted'], true) && Erp::can('stock.count', $c->warehouse),
                'import' => $c->status === 'counting' && Erp::canAnywhere('stock.count') ? '/office/import?'.http_build_query(['type' => 'stock_count', 'context' => $c->id]) : null,
            ],
        ];
    }
}
