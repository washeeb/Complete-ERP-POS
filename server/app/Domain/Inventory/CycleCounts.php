<?php

namespace App\Domain\Inventory;

use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\StockCount;
use App\Models\Warehouse;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cycle counting (Stock Controller release 2): instead of one big stock-take, a few products are counted each day, chosen
 * by how much value moves through the store. A products (the top 80% of the value sold or used in the last 90 days) are
 * counted every 30 days, B (the next 15%) every 60, C (the rest) every 120; never counted comes first, then the most
 * overdue. Products whose counts keep coming out wrong (a difference in two of their last three counts) are counted
 * again sooner. The day's list becomes an ordinary blind stock count, submitted and posted as usual.
 */
final class CycleCounts
{
    public const INTERVAL_DAYS = ['A' => 30, 'B' => 60, 'C' => 120];

    public function __construct(private readonly Settings $settings, private readonly StockCountService $counts) {}

    public function perDay(string $tenantId): int
    {
        return (int) $this->settings->get('stock.cycle_count_per_day', $tenantId);
    }

    /**
     * Products due for counting in a store, most urgent first.
     *
     * @return Collection<int, object{product_id: string, sku: string, name: string, class: string, last_counted: ?string, due_in: int, often_wrong: bool}>
     */
    public function due(Warehouse $warehouse, ?int $limit = null, ?Carbon $today = null): Collection
    {
        $today ??= now();
        $rows = DB::table('stock_levels as sl')->join('products as p', 'p.id', '=', 'sl.product_id')
            ->where('sl.warehouse_id', $warehouse->id)->where('p.track_stock', true)->where('p.is_active', true)->where('sl.on_hand', '<>', 0)
            ->get(['p.id as product_id', 'p.sku', 'p.name', 'sl.avg_cost_minor']);
        if ($rows->isEmpty()) {
            return collect();
        }
        $ids = $rows->pluck('product_id')->all();
        $moved = DB::table('stock_movements')->where('warehouse_id', $warehouse->id)->whereIn('product_id', $ids)
            ->whereIn('type', ['sale', 'transfer_out', 'adjustment'])->where('qty_base', '<', 0)->where('occurred_at', '>=', $today->copy()->subDays(90))
            ->groupBy('product_id')->selectRaw('product_id, SUM(-qty_base) AS qty')->pluck('qty', 'product_id');
        $lastCounted = $this->lastCounted($warehouse, $ids);
        $wrong = $this->oftenWrongIds($warehouse, $ids);

        // ABC by value moved: cumulative share of the total.
        $value = $rows->mapWithKeys(fn ($r) => [$r->product_id => (float) ($moved[$r->product_id] ?? 0) * (float) ($r->avg_cost_minor ?? 0)])->sortDesc();
        $total = max(1.0, $value->sum());
        $class = [];
        $running = 0.0;
        foreach ($value as $id => $v) {
            $class[$id] = $v <= 0 ? 'C' : ($running / $total < 0.80 ? 'A' : ($running / $total < 0.95 ? 'B' : 'C'));
            $running += $v;
        }

        return $rows->map(function ($r) use ($class, $lastCounted, $wrong, $today) {
            $c = $class[$r->product_id];
            $last = $lastCounted[$r->product_id] ?? null;
            $interval = self::INTERVAL_DAYS[$c] / (in_array($r->product_id, $wrong, true) ? 2 : 1); // often wrong: twice as often
            $dueIn = $last ? (int) floor($interval - Carbon::parse($last)->diffInDays($today, true)) : -999;

            return (object) ['product_id' => $r->product_id, 'sku' => $r->sku, 'name' => $r->name, 'class' => $c,
                'last_counted' => $last ? Carbon::parse($last)->toDateString() : null, 'due_in' => $dueIn, 'often_wrong' => in_array($r->product_id, $wrong, true)];
        })->filter(fn ($r) => $r->due_in <= 0)
            ->sortBy([['due_in', 'asc'], ['class', 'asc'], ['name', 'asc']])->values()
            ->when($limit !== null, fn ($c) => $c->take($limit));
    }

    /** Today's cycle count in this store, if one was started. */
    public function today(Warehouse $warehouse): ?StockCount
    {
        return StockCount::where('warehouse_id', $warehouse->id)->where('note', self::note())->whereNot('status', 'cancelled')->latest('started_at')->first();
    }

    /** Start today's count: the products due, as a blind count. */
    public function start(Actor $actor, Warehouse $warehouse): StockCount
    {
        if ($existing = $this->today($warehouse)) {
            throw new DomainException("Today's count for this store is {$existing->number}.");
        }
        $due = $this->due($warehouse, max(1, $this->perDay($actor->tenantId)));
        if ($due->isEmpty()) {
            throw new DomainException('Nothing is due for counting in this store today.');
        }

        return $this->counts->start($actor, $warehouse, null, true, $due->pluck('product_id')->all(), self::note());
    }

    /**
     * Products whose counts keep coming out wrong: a difference in two of their last three posted counts.
     *
     * @return list<string>
     */
    public function oftenWrongIds(Warehouse $warehouse, ?array $productIds = null): array
    {
        $lines = DB::table('stock_count_lines as l')->join('stock_counts as c', 'c.id', '=', 'l.stock_count_id')
            ->where('c.warehouse_id', $warehouse->id)->where('c.status', 'posted')->whereNotNull('l.counted_qty')
            ->when($productIds !== null, fn ($q) => $q->whereIn('l.product_id', $productIds))
            ->orderByDesc('c.posted_at')->get(['l.product_id', 'l.expected_qty', 'l.counted_qty']);

        return $lines->groupBy('product_id')->filter(fn ($g) => $g->take(3)->filter(fn ($l) => (float) $l->counted_qty !== (float) $l->expected_qty)->count() >= 2)
            ->keys()->values()->all();
    }

    /** @return array<string, string> product id => when it was last counted (posted) here */
    private function lastCounted(Warehouse $warehouse, array $productIds): array
    {
        return DB::table('stock_count_lines as l')->join('stock_counts as c', 'c.id', '=', 'l.stock_count_id')
            ->where('c.warehouse_id', $warehouse->id)->where('c.status', 'posted')->whereNotNull('l.counted_qty')->whereIn('l.product_id', $productIds)
            ->groupBy('l.product_id')->selectRaw('l.product_id, MAX(c.posted_at) AS at')->pluck('at', 'product_id')->all();
    }

    public static function note(?Carbon $day = null): string
    {
        return 'Cycle count '.($day ?? now())->toDateString();
    }
}
