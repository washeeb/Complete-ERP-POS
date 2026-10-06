<?php

namespace App\Domain\Inventory;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Bin;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Qty;
use Illuminate\Support\Facades\DB;

/** Bin locations per warehouse (switched on per warehouse — owner decision 2026-10-09) and put-away between bins. */
final class BinService
{
    public const RECEIVING_CODE = 'RECV';

    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly StockLedger $ledger) {}

    public static function receivingBin(Warehouse $warehouse): Bin
    {
        return Bin::where('warehouse_id', $warehouse->id)->where('kind', 'receiving')->orderBy('created_at')->first()
            ?? throw new DomainException("{$warehouse->name} uses bins but has no receiving bin. Switch bins off and on again to create it.");
    }

    /**
     * Switching on: a receiving bin is created and all existing stock is placed in it (put it away from there).
     * Switching off is refused while any bin holds stock, so the bin figures can never silently disappear.
     */
    public function setUseBins(Actor $actor, Warehouse $warehouse, bool $on): Warehouse
    {
        $this->require($actor, $warehouse);
        if ($warehouse->use_bins === $on) {
            return $warehouse;
        }

        return $this->recorder->transaction(function () use ($actor, $warehouse, $on) {
            if (! $on) {
                if (DB::table('stock_bin_levels')->where('warehouse_id', $warehouse->id)->where('on_hand', '<>', 0)->exists()) {
                    throw new DomainException('Bins still hold stock. Bins can be switched off only when every bin is empty.');
                }
                $warehouse->use_bins = false;

                return $this->recorder->save($actor, $warehouse, 'warehouse.bins_off');
            }
            $recv = Bin::where('warehouse_id', $warehouse->id)->where('kind', 'receiving')->first();
            if (! $recv) {
                $recv = new Bin(['warehouse_id' => $warehouse->id, 'code' => self::RECEIVING_CODE, 'name' => 'Receiving dock', 'kind' => 'receiving', 'pick_order' => 9999]);
                $recv->tenant_id = $warehouse->tenant_id;
                $this->recorder->save($actor, $recv, 'bin.created');
            }
            $warehouse->use_bins = true;
            $this->recorder->save($actor, $warehouse, 'warehouse.bins_on');

            // Place stock already in the warehouse into the receiving bin (net zero for the warehouse).
            $levels = DB::table('stock_levels')->where('warehouse_id', $warehouse->id)->where('on_hand', '<>', 0)->get();
            foreach ($levels as $l) {
                $product = Product::find($l->product_id);
                $base = ProductUom::where('product_id', $l->product_id)->where('is_base', true)->first();
                $qty = Qty::toMilli((string) $l->on_hand);
                $binned = Qty::toMilli((string) (DB::table('stock_bin_levels')->where('warehouse_id', $warehouse->id)->where('product_id', $l->product_id)->sum('on_hand')));
                if ($product && $base && $qty !== $binned) {
                    $this->ledger->post($actor, $warehouse->fresh(), 'putaway', [
                        ['product' => $product, 'unit' => $base, 'qty_milli' => $qty - $binned, 'bin_id' => $recv->id],
                    ], 'Bins switched on: existing stock placed in the receiving bin', 'warehouse', $warehouse->id);
                }
            }

            return $warehouse;
        });
    }

    public function saveBin(Actor $actor, Bin $bin, array $data): Bin
    {
        $warehouse = Warehouse::findOrFail($data['warehouse_id'] ?? $bin->warehouse_id);
        $this->require($actor, $warehouse);
        if (! $warehouse->use_bins) {
            throw new DomainException("Switch bins on for {$warehouse->name} first.");
        }
        $code = strtoupper(trim((string) ($data['code'] ?? $bin->code)));
        if (! preg_match('/^[A-Z0-9][A-Z0-9\-\.]{0,29}$/', $code)) {
            throw new DomainException('Bin codes use letters, digits, dots and dashes (e.g. A-01-02).');
        }
        if (Bin::where('warehouse_id', $warehouse->id)->where('code', $code)->when($bin->exists, fn ($q) => $q->whereKeyNot($bin->id))->exists()) {
            throw new DomainException("Bin {$code} already exists in {$warehouse->name}.");
        }
        if ($bin->exists && $bin->kind === 'receiving' && isset($data['is_active']) && ! $data['is_active']) {
            throw new DomainException('The receiving bin cannot be switched off.');
        }
        if ($bin->exists && isset($data['is_active']) && ! $data['is_active']
            && DB::table('stock_bin_levels')->where('bin_id', $bin->id)->where('on_hand', '<>', 0)->exists()) {
            throw new DomainException("Bin {$bin->code} still holds stock. Move it out first.");
        }
        $bin->fill(['warehouse_id' => $warehouse->id, 'code' => $code] + array_intersect_key($data, array_flip(['name', 'pick_order', 'is_active'])));
        if (! $bin->exists) {
            $bin->tenant_id = $warehouse->tenant_id;
            $bin->kind = 'storage';
        }

        return $this->recorder->save($actor, $bin, $bin->exists ? 'bin.updated' : 'bin.created');
    }

    /** Move stock between two bins of the same warehouse (the warehouse total does not change). */
    public function putAway(Actor $actor, Warehouse $warehouse, Product $product, ProductUom $unit, string $qty, string $fromBinId, string $toBinId): array
    {
        if (! $actor->user || ! ($this->authz->allows($actor->user, 'stock.receive', $warehouse) || $this->authz->allows($actor->user, 'stock.transfer', $warehouse))) {
            throw new AccessDenied;
        }
        if (! $warehouse->use_bins) {
            throw new DomainException("{$warehouse->name} does not use bins.");
        }
        $milli = Qty::toMilli($qty);
        if ($milli <= 0) {
            throw new DomainException('Enter a quantity above zero.');
        }
        if ($fromBinId === $toBinId) {
            throw new DomainException('Choose a different bin to move to.');
        }
        $to = Bin::where('warehouse_id', $warehouse->id)->where('is_active', true)->find($toBinId) ?? throw new DomainException('That bin is not in this warehouse or is switched off.');

        return $this->recorder->transaction(fn () => $this->ledger->post($actor, $warehouse, 'putaway', [
            ['product' => $product, 'unit' => $unit, 'qty_milli' => -$milli, 'bin_id' => $fromBinId],
            ['product' => $product, 'unit' => $unit, 'qty_milli' => $milli, 'bin_id' => $to->id],
        ], "Moved to {$to->code}", 'putaway', null));
    }

    private function require(Actor $actor, Warehouse $warehouse): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'warehouse.manage', $warehouse)) {
            throw new AccessDenied;
        }
    }
}
