<?php

namespace App\Domain\Distribution;

use App\Domain\Inventory\TransferService;
use App\Domain\Modules\ModuleService;
use App\Domain\Org\OrgService;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\StockTransfer;
use App\Models\Till;
use App\Models\User;
use App\Models\Warehouse;

/**
 * Vans (owner decision: each van is a warehouse). A van is a warehouse of its branch with its own till, so the rep's
 * phone sells from the van's stock with the till app — fully offline — and every sale, receipt, shift and cash count
 * works as in a shop. The van is loaded from its home warehouse (a stock transfer, dispatched and received at once
 * because both are at the depot) and settled at the end of the day.
 */
final class VanService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly OrgService $org,
        private readonly TransferService $transfers,
        private readonly ModuleService $modules,
    ) {}

    /** @param array{code: string, name: string, vehicle_reg?: ?string, rep_user_id?: ?string, home_warehouse_id: string} $data */
    public function create(Actor $actor, Branch $branch, array $data): Warehouse
    {
        if (! $this->modules->isEnabled($branch, 'distribution')) {
            throw new DomainException('Switch on Sales & Distribution for this branch first (System → Modules).', 'module_disabled');
        }
        $home = Warehouse::where('branch_id', $branch->id)->where('is_van', false)->find($data['home_warehouse_id'] ?? null)
            ?? throw new DomainException('Choose the warehouse the van is loaded from (in the same branch).');
        if (! empty($data['rep_user_id']) && ! User::whereKey($data['rep_user_id'])->exists()) {
            throw new DomainException('Choose the rep from the users.');
        }

        return $this->recorder->transaction(function () use ($actor, $branch, $data, $home) {
            $van = $this->org->saveWarehouse($actor, new Warehouse, ['branch_id' => $branch->id, 'code' => $data['code'], 'name' => $data['name'], 'is_active' => true]);
            $van->forceFill(['is_van' => true, 'vehicle_reg' => mb_strtoupper(trim((string) ($data['vehicle_reg'] ?? ''))) ?: null,
                'rep_user_id' => $data['rep_user_id'] ?? null, 'home_warehouse_id' => $home->id]);
            $this->recorder->save($actor, $van, 'van.created', branchId: $branch->id);
            // The van's till: its receipts are numbered like any till's (branch-till-series-number).
            $this->org->saveTill($actor, new Till, ['branch_id' => $branch->id, 'warehouse_id' => $van->id, 'code' => $van->code, 'name' => "Van {$van->name}", 'is_active' => true]);

            return $van;
        });
    }

    public function update(Actor $actor, Warehouse $van, array $data): Warehouse
    {
        $this->org->saveWarehouse($actor, $van, array_intersect_key($data, array_flip(['name', 'is_active'])));
        $van->forceFill(array_intersect_key($data, array_flip(['vehicle_reg', 'rep_user_id', 'home_warehouse_id'])));

        return $this->recorder->save($actor, $van, 'van.updated', branchId: $van->branch_id);
    }

    /**
     * Load the van from its home warehouse (or bring stock back: $unload). Both ends are at the depot, so the transfer is
     * dispatched and received in one go; it keeps the usual audit trail and stock movements on both sides.
     *
     * @param  list<array{product_uom_id: string, qty: string}>  $lines
     */
    public function load(Actor $actor, Warehouse $van, array $lines, bool $unload = false, ?string $note = null): StockTransfer
    {
        if (! $van->is_van || ! $van->home_warehouse_id) {
            throw new DomainException('That warehouse is not a van.');
        }
        $home = Warehouse::findOrFail($van->home_warehouse_id);
        [$from, $to] = $unload ? [$van, $home] : [$home, $van];

        return $this->recorder->transaction(function () use ($actor, $from, $to, $lines, $unload, $note, $van) {
            $t = $this->transfers->saveDraft($actor, new StockTransfer, ['from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id, 'lines' => $lines,
                'note' => $note ?? ($unload ? "Unloaded from van {$van->code}" : "Van {$van->code} loaded")]);
            $this->transfers->dispatch($actor, $t);

            return $this->transfers->receive($actor, $t->fresh());
        });
    }

    /** The van behind a till, if the till is a van's. */
    public static function vanOfTill(?Till $till): ?Warehouse
    {
        $wh = $till ? Warehouse::find($till->warehouse_id) : null;

        return $wh?->is_van ? $wh : null;
    }
}
