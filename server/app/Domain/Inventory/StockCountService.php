<?php

namespace App\Domain\Inventory;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Bin;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Qty;
use Illuminate\Support\Facades\DB;

/**
 * Stock counts. The expected quantities are frozen when the count starts; posting books the difference
 * (counted − expected) as count movements. Sales and receipts during the count are kept as they are — so count
 * an area when it is quiet, or count it before it is sold from. Blind by default: counters never see the
 * expected figure. Lines nobody counted are left alone (not set to zero). The person who posts must not be the
 * one who submitted (segregation of duties).
 */
final class StockCountService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly StockLedger $ledger,
    ) {}

    /** @param list<string>|null $productIds  null = everything with stock in the scope (plus whatever is scanned later) */
    public function start(Actor $actor, Warehouse $warehouse, ?Bin $bin = null, bool $blind = true, ?array $productIds = null, ?string $note = null): StockCount
    {
        $this->require($actor, 'stock.count', $warehouse);
        if ($bin && $bin->warehouse_id !== $warehouse->id) {
            throw new DomainException('That bin is not in this warehouse.');
        }
        if (StockCount::where('warehouse_id', $warehouse->id)->where('status', 'counting')
            ->where(fn ($q) => $q->whereNull('bin_id')->when($bin, fn ($q2) => $q2->orWhere('bin_id', $bin->id)))->exists()) {
            throw new DomainException('A count is already open for this area. Finish or cancel it first.');
        }

        return $this->recorder->transaction(function () use ($actor, $warehouse, $bin, $blind, $productIds, $note) {
            $count = new StockCount;
            $count->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'stock_count'),
                'branch_id' => $warehouse->branch_id, 'warehouse_id' => $warehouse->id, 'bin_id' => $bin?->id, 'status' => 'counting',
                'blind' => $blind, 'note' => $note, 'started_at' => now(), 'started_by_name' => $actor->name]);
            $this->recorder->save($actor, $count, 'stock_count.started', branchId: $warehouse->branch_id);

            foreach ($this->snapshot($warehouse, $bin, $productIds) as $row) {
                StockCountLine::create(['stock_count_id' => $count->id, 'product_id' => $row->product_id, 'bin_id' => $row->bin_id, 'expected_qty' => $row->qty]);
            }
            $this->recorder->outbox->publish($actor, $count->fresh(), 'stock_count.lines');

            return $count->fresh('lines');
        });
    }

    /** Record a counted quantity (replaces an earlier entry for the same product and bin). */
    public function record(Actor $actor, StockCount $count, Product $product, ProductUom $unit, string $qty, ?string $binId = null): StockCountLine
    {
        $warehouse = $count->warehouse;
        $this->require($actor, 'stock.count', $warehouse);
        if ($count->status !== 'counting') {
            throw new DomainException("{$count->number} is no longer open for counting.");
        }
        if ($unit->product_id !== $product->id) {
            throw new DomainException('That unit belongs to another product.');
        }
        $binId = $count->bin_id ?? ($warehouse->use_bins ? ($binId ?? throw new DomainException('Scan the bin you are counting first.')) : null);
        if ($binId && ! Bin::where('warehouse_id', $warehouse->id)->whereKey($binId)->exists()) {
            throw new DomainException('That bin is not in this warehouse.');
        }
        $milli = Qty::toMilli($qty);
        if ($milli < 0) {
            throw new DomainException('A count cannot be negative.');
        }
        $base = Qty::toBaseMilli($milli, Qty::factorMicro($unit->factor));

        return $this->recorder->transaction(function () use ($actor, $count, $product, $binId, $base) {
            $line = StockCountLine::where('stock_count_id', $count->id)->where('product_id', $product->id)
                ->where(fn ($q) => $binId ? $q->where('bin_id', $binId) : $q->whereNull('bin_id'))->lockForUpdate()->first();
            if (! $line) { // scanned something not expected here: expected = what the ledger held at the start
                $line = new StockCountLine(['stock_count_id' => $count->id, 'product_id' => $product->id, 'bin_id' => $binId,
                    'expected_qty' => Qty::format($this->ledgerAt($count, $product->id, $binId))]);
            }
            $line->forceFill(['counted_qty' => Qty::format($base), 'counted_by_name' => $actor->name, 'counted_at' => now()])->save();
            $this->recorder->audit->record($actor, 'stock_count.counted', $count, [$product->sku => [null, Qty::format($base)]], null, $count->branch_id);
            $count->touch();
            $this->recorder->outbox->publish($actor, $count->fresh(), 'stock_count.counted');

            return $line;
        });
    }

    public function submit(Actor $actor, StockCount $count): StockCount
    {
        $this->require($actor, 'stock.count', $count->warehouse);
        if ($count->status !== 'counting') {
            throw new DomainException("{$count->number} is not open for counting.");
        }
        if (! $count->lines()->whereNotNull('counted_qty')->exists()) {
            throw new DomainException('Nothing has been counted yet.');
        }
        $count->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => $actor->id, 'submitted_by_name' => $actor->name]);

        return $this->recorder->save($actor, $count, 'stock_count.submitted', branchId: $count->branch_id);
    }

    /** @return array{lines: int, value_minor: int} */
    public function post(Actor $actor, StockCount $count): array
    {
        $warehouse = $count->warehouse;
        $this->require($actor, 'stock.count.approve', $warehouse);
        if ($count->status !== 'submitted') {
            throw new DomainException("{$count->number} has not been submitted.");
        }
        if ($count->submitted_by && $count->submitted_by === $actor->id) {
            throw new DomainException('You submitted this count, so someone else must approve it.', 'own_document');
        }

        return $this->recorder->transaction(function () use ($actor, $count, $warehouse) {
            $count = StockCount::lockForUpdate()->findOrFail($count->id);
            $entries = [];
            $value = 0;
            foreach ($count->lines()->whereNotNull('counted_qty')->get() as $l) {
                $diff = Qty::toMilli((string) $l->counted_qty) - Qty::toMilli((string) $l->expected_qty);
                if ($diff === 0) {
                    continue;
                }
                $product = Product::findOrFail($l->product_id);
                $base = ProductUom::where('product_id', $product->id)->where('is_base', true)->firstOrFail();
                $entries[] = ['product' => $product, 'unit' => $base, 'qty_milli' => $diff, 'bin_id' => $l->bin_id];
                $avg = DB::table('stock_levels')->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('avg_cost_minor');
                $value += $avg === null ? 0 : (int) round($diff * (float) $avg / 1000);
            }
            if ($entries) {
                $this->ledger->post($actor, $warehouse, 'count', $entries, "Stock count {$count->number}", 'stock_count', $count->id);
            }
            $count->forceFill(['status' => 'posted', 'posted_at' => now(), 'posted_by_name' => $actor->name, 'variance_value_minor' => $value]);
            $this->recorder->save($actor, $count, 'stock_count.posted', branchId: $count->branch_id);

            return ['lines' => count($entries), 'value_minor' => $value];
        });
    }

    public function cancel(Actor $actor, StockCount $count, string $reason): StockCount
    {
        $this->require($actor, 'stock.count', $count->warehouse);
        if (! in_array($count->status, ['counting', 'submitted'], true)) {
            throw new DomainException("{$count->number} is already {$count->status}.");
        }
        $count->status = 'cancelled';

        return $this->recorder->save($actor, $count, 'stock_count.cancelled', $reason, $count->branch_id);
    }

    /** Lines to count: products with stock in the scope, per bin when the warehouse uses bins. */
    private function snapshot(Warehouse $warehouse, ?Bin $bin, ?array $productIds)
    {
        $q = $warehouse->use_bins
            ? DB::table('stock_bin_levels')->where('warehouse_id', $warehouse->id)->when($bin, fn ($x) => $x->where('bin_id', $bin->id))
                ->selectRaw('product_id, bin_id, on_hand AS qty')
            : DB::table('stock_levels')->where('warehouse_id', $warehouse->id)->selectRaw('product_id, NULL::uuid AS bin_id, on_hand AS qty');
        $rows = $q->when($productIds !== null, fn ($x) => $x->whereIn('product_id', $productIds), fn ($x) => $x->where('on_hand', '<>', 0))
            ->lockForUpdate()->get();
        if ($productIds !== null) { // listed products with no stock row yet are expected at zero
            $have = $rows->pluck('product_id')->all();
            foreach (array_diff($productIds, $have) as $id) {
                $rows->push((object) ['product_id' => $id, 'bin_id' => $bin?->id, 'qty' => '0.000']);
            }
        }

        return $rows;
    }

    /** Ledger balance at the moment the count started (for items scanned that were not on the list). */
    private function ledgerAt(StockCount $count, string $productId, ?string $binId): int
    {
        $sum = DB::table('stock_movements')->where('warehouse_id', $count->warehouse_id)->where('product_id', $productId)
            ->when($binId, fn ($q) => $q->where('bin_id', $binId))->where('received_at', '<=', $count->started_at)->sum('qty_base');

        return Qty::toMilli(number_format((float) $sum, 3, '.', ''));
    }

    private function require(Actor $actor, string $permission, Warehouse $warehouse): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, $permission, $warehouse)) {
            throw new AccessDenied;
        }
    }
}
