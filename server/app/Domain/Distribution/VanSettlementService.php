<?php

namespace App\Domain\Distribution;

use App\Domain\Inventory\StockLedger;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\VanSettlement;
use App\Models\VanSettlementLine;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Qty;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * End-of-day van settlement (owner decision 2026-10-23: load plus daily settlement). Back at the depot:
 *
 * 1. **Start** — the system lists what should be on the van (the stock ledger) and the day's sales and cash from the
 *    rep's shift(s) on the van's till.
 * 2. **Count** — the storekeeper and rep count what is left on the van, and say how much goes back into the warehouse
 *    (the rest stays loaded for tomorrow).
 * 3. **Submit**, then a supervisor (not the person who submitted) **settles** it: shortages and surpluses are booked as
 *    count movements on the van (with their value), and the unloaded stock is transferred back.
 *
 * The cash side is the till's: the rep closes the shift with the cash count; the settlement shows expected, counted and
 * over/short so both are signed off together.
 */
final class VanSettlementService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly StockLedger $ledger,
        private readonly VanService $vans,
    ) {}

    public function start(Actor $actor, Warehouse $van, ?string $date = null): VanSettlement
    {
        $this->require($actor, 'stock.transfer', $van);
        if (! $van->is_van) {
            throw new DomainException('That warehouse is not a van.');
        }
        if (VanSettlement::where('van_warehouse_id', $van->id)->whereIn('status', ['counting', 'submitted'])->exists()) {
            throw new DomainException('A settlement is already open for this van. Finish or cancel it first.');
        }
        $tz = Branch::find($van->branch_id)?->timezone ?: (Tenant::find($van->tenant_id)?->timezone ?: 'UTC');
        $day = Carbon::parse($date ?? now()->setTimezone($tz)->toDateString(), $tz)->startOfDay();

        return $this->recorder->transaction(function () use ($actor, $van, $day) {
            [$sales, $expected, $counted] = $this->cash($van, $day);
            $s = (new VanSettlement)->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'van_settlement'),
                'branch_id' => $van->branch_id, 'van_warehouse_id' => $van->id, 'return_warehouse_id' => $van->home_warehouse_id, 'rep_user_id' => $van->rep_user_id,
                'business_date' => $day->toDateString(), 'status' => 'counting', 'sales_minor' => $sales, 'cash_expected_minor' => $expected, 'cash_counted_minor' => $counted,
                'started_by_name' => $actor->name]);
            $this->recorder->save($actor, $s, 'van_settlement.started', branchId: $van->branch_id);
            foreach (DB::table('stock_levels')->where('warehouse_id', $van->id)->where('on_hand', '<>', 0)->get(['product_id', 'on_hand']) as $l) {
                VanSettlementLine::create(['van_settlement_id' => $s->id, 'product_id' => $l->product_id, 'expected_qty' => $l->on_hand]);
            }

            return $s->fresh('lines');
        });
    }

    /** Counted quantities and what goes back, per product (base units): [product_id => ['counted' => '…', 'unload' => '…']]. */
    public function count(Actor $actor, VanSettlement $s, array $counts): VanSettlement
    {
        $van = Warehouse::findOrFail($s->van_warehouse_id);
        $this->require($actor, 'stock.transfer', $van);
        if ($s->status !== 'counting') {
            throw new DomainException("{$s->number} is no longer open for counting.");
        }

        return $this->recorder->transaction(function () use ($actor, $s, $counts) {
            foreach ($counts as $productId => $c) {
                $counted = isset($c['counted']) && trim((string) $c['counted']) !== '' ? Qty::toMilli((string) $c['counted']) : null;
                $unload = isset($c['unload']) && trim((string) $c['unload']) !== '' ? Qty::toMilli((string) $c['unload']) : 0;
                $product = Product::find($productId) ?? throw new DomainException('Unknown product in the count.');
                if (($counted !== null && $counted < 0) || $unload < 0 || ($counted !== null && $unload > $counted)) {
                    throw new DomainException("{$product->name}: counts cannot be negative, and you can only unload what is on the van.");
                }
                $line = VanSettlementLine::firstOrNew(['van_settlement_id' => $s->id, 'product_id' => $productId]);
                $line->expected_qty ??= '0';
                $line->forceFill(['counted_qty' => $counted === null ? null : Qty::format($counted), 'unload_qty' => Qty::format($unload)])->save();
            }
            $this->recorder->audit->record($actor, 'van_settlement.counted', $s, ['lines' => [null, count($counts)]], null, $s->branch_id);
            $s->touch();

            return $s->fresh('lines');
        });
    }

    public function submit(Actor $actor, VanSettlement $s, ?string $note = null): VanSettlement
    {
        $this->require($actor, 'stock.transfer', Warehouse::findOrFail($s->van_warehouse_id));
        if ($s->status !== 'counting') {
            throw new DomainException("{$s->number} is not open for counting.");
        }
        if ($s->lines()->whereNull('counted_qty')->exists()) {
            throw new DomainException('Count every product on the van (0 if none is left) before submitting.');
        }
        [$sales, $expected, $counted] = $this->cash(Warehouse::findOrFail($s->van_warehouse_id), Carbon::parse($s->business_date));
        $s->forceFill(['status' => 'submitted', 'submitted_by' => $actor->id, 'submitted_by_name' => $actor->name, 'note' => $note ?? $s->note,
            'sales_minor' => $sales, 'cash_expected_minor' => $expected, 'cash_counted_minor' => $counted]);

        return $this->recorder->save($actor, $s, 'van_settlement.submitted', branchId: $s->branch_id);
    }

    /** Book the differences on the van and send the unloaded stock back. */
    public function post(Actor $actor, VanSettlement $s): VanSettlement
    {
        $van = Warehouse::findOrFail($s->van_warehouse_id);
        $this->require($actor, 'distribution.settle', $van);
        if ($s->status !== 'submitted') {
            throw new DomainException("{$s->number} has not been submitted.");
        }
        if ($s->submitted_by === $actor->id) {
            throw new DomainException('You submitted this settlement, so someone else must approve it.', 'own_document');
        }

        return $this->recorder->transaction(function () use ($actor, $s, $van) {
            $s = VanSettlement::lockForUpdate()->findOrFail($s->id);
            $entries = [];
            $unload = [];
            $value = 0;
            foreach ($s->lines()->with('product')->get() as $l) {
                $diff = Qty::toMilli((string) $l->counted_qty) - Qty::toMilli((string) $l->expected_qty);
                $base = ProductUom::where('product_id', $l->product_id)->where('is_base', true)->firstOrFail();
                if ($diff !== 0) {
                    $entries[] = ['product' => $l->product, 'unit' => $base, 'qty_milli' => $diff];
                    $avg = DB::table('stock_levels')->where('warehouse_id', $van->id)->where('product_id', $l->product_id)->value('avg_cost_minor');
                    $value += $avg === null ? 0 : (int) round($diff * (float) $avg / 1000);
                }
                if (Qty::toMilli((string) $l->unload_qty) > 0) {
                    $unload[] = ['product_uom_id' => $base->id, 'qty' => (string) $l->unload_qty];
                }
            }
            if ($entries) {
                $this->ledger->post($actor, $van, 'count', $entries, "Van settlement {$s->number}", 'van_settlement', $s->id);
            }
            $transfer = $unload ? $this->vans->load($actor, $van, $unload, unload: true, note: "Van settlement {$s->number}: unloaded") : null;
            $s->forceFill(['status' => 'posted', 'posted_by_name' => $actor->name, 'posted_at' => now(), 'stock_variance_minor' => $value,
                'unload_transfer_id' => $transfer?->id]);

            return $this->recorder->save($actor, $s->load('lines'), 'van_settlement.posted', branchId: $s->branch_id);
        });
    }

    public function cancel(Actor $actor, VanSettlement $s, string $reason): VanSettlement
    {
        $this->require($actor, 'stock.transfer', Warehouse::findOrFail($s->van_warehouse_id));
        if (! in_array($s->status, ['counting', 'submitted'], true) || trim($reason) === '') {
            throw new DomainException(trim($reason) === '' ? 'Give the reason.' : 'Only an open settlement can be cancelled.');
        }
        $s->status = 'cancelled';

        return $this->recorder->save($actor, $s, 'van_settlement.cancelled', $reason, $s->branch_id);
    }

    /** The van till's sales total and cash for the day: [sales, expected cash, counted cash] (null when no shift closed). */
    private function cash(Warehouse $van, Carbon $day): array
    {
        $tills = Till::where('warehouse_id', $van->id)->pluck('id');
        $from = $day->copy()->startOfDay()->utc();
        $to = $day->copy()->addDay()->startOfDay()->utc();
        $sales = (int) DB::table('sales')->whereIn('till_id', $tills)->where('status', 'posted')->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to)->sum('total_minor');
        $shifts = Shift::whereIn('till_id', $tills)->where('opened_at', '<', $to)->where(fn ($q) => $q->whereNull('closed_at')->orWhere('closed_at', '>=', $from))->get();
        $closed = $shifts->whereNotNull('closed_at');

        return [$sales, $closed->isEmpty() ? null : (int) $closed->sum('expected_cash_minor'), $closed->isEmpty() ? null : (int) $closed->sum('counted_cash_minor')];
    }

    private function require(Actor $actor, string $permission, Warehouse $warehouse): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, $permission, $warehouse)) {
            throw new AccessDenied;
        }
    }
}
