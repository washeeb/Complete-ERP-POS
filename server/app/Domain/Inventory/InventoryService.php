<?php

namespace App\Domain\Inventory;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Money;
use App\Support\Qty;
use Illuminate\Support\Facades\DB;

/**
 * Manual stock changes from the back office. Opening balances (once per product and warehouse)
 * and adjustments with a reason. Counts, receipts and transfers arrive in Phase 5;
 * approval of large adjustments arrives with them.
 */
final class InventoryService
{
    public const ADJUSTMENT_REASONS = [
        'damaged' => 'Damaged',
        'expired' => 'Expired',
        'spoilage' => 'Spoilage (fresh produce)',
        'kitchen_waste' => 'Kitchen waste',
        'consignment_in' => 'Consignment received (no cost)',
        'consignment_out' => 'Consignment returned to the consignor',
        'theft' => 'Theft / missing',
        'found' => 'Found / surplus',
        'own_use' => 'Own use / sample',
        'correction' => 'Correction of an earlier mistake',
    ];

    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly StockLedger $ledger,
    ) {}

    /** $qty is the signed change in the chosen unit, as a decimal string ("-2", "1.5"). */
    /** Correct a batch's expiry date (a mistyped date on receipt). Audited and synced; the batch keeps its stock. */
    public function correctBatchExpiry(Actor $actor, StockBatch $batch, string $expiresOn): StockBatch
    {
        if (! $actor->user || ! $this->authz->allowsAnywhere($actor->user, 'stock.adjust')) {
            throw new AccessDenied;
        }
        $batch->expires_on = $expiresOn;

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $batch, 'stock_batch.expiry_corrected', 'Expiry date corrected'));
    }

    public function adjust(Actor $actor, Warehouse $warehouse, Product $product, ProductUom $unit, string $qty, string $type, string $reasonCode, ?string $note = null, ?string $unitCost = null,
        ?string $batchNo = null, ?string $expiresOn = null, array|string|null $serials = null): StockMovement
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'stock.adjust', $warehouse)) {
            throw new AccessDenied;
        }
        if (! in_array($type, ['opening', 'adjustment'], true)) {
            throw new DomainException('Unknown stock change type.');
        }
        if ($type === 'adjustment' && ! array_key_exists($reasonCode, self::ADJUSTMENT_REASONS)) {
            throw new DomainException('Choose a reason for the adjustment.');
        }
        if (! $product->track_stock) {
            throw new DomainException("{$product->name} is not stock-tracked.");
        }
        $milli = Qty::toMilli($qty);
        if ($milli === 0) {
            throw new DomainException('Enter a quantity other than zero.');
        }
        if ($type === 'opening') {
            if ($milli < 0) {
                throw new DomainException('An opening balance cannot be negative.');
            }
            $hasHistory = StockMovement::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->exists();
            if ($hasHistory) {
                throw new DomainException('This product already has stock history here; use an adjustment instead.');
            }
        }
        $decimals = $unit->uom->decimals;
        if ($milli % (10 ** (3 - $decimals)) !== 0) {
            throw new DomainException("{$unit->uom->name} allows {$decimals} decimal place(s).");
        }
        $cost = $unitCost !== null && $unitCost !== '' ? Money::toMinor($unitCost, $actor->user->tenant->currency_code) : null;

        $reason = $type === 'opening' ? 'Opening balance' : self::ADJUSTMENT_REASONS[$reasonCode].($note ? ": {$note}" : '');

        return $this->recorder->transaction(function () use ($actor, $warehouse, $product, $unit, $milli, $type, $reason, $cost, $batchNo, $expiresOn, $serials) {
            // Batch-tracked: a named batch (new or existing); without one, stock out is taken earliest expiry first.
            $batch = $product->track_batches && trim((string) $batchNo) !== '' ? ['batch_id' => app(StockBatches::class)->ensure($actor, $product, (string) $batchNo, $expiresOn)] : [];
            [$movement] = $this->ledger->post($actor, $warehouse, $type, [
                ['product' => $product, 'unit' => $unit, 'qty_milli' => $milli, 'unit_cost_minor' => $cost, 'serials' => $serials] + $batch,
            ], $reason, 'manual', null);
            $this->recorder->audit->record($actor, "stock.{$type}", $product, [
                'warehouse' => [null, $warehouse->code],
                'qty' => [null, Qty::format($milli).' '.$unit->uom->code],
            ], $reason, $warehouse->branch_id);

            return $movement;
        });
    }

    /**
     * Serial numbers for units already on hand (stock held before serial tracking was switched on, or count gains):
     * registers them in stock here, up to the quantity on hand that has no number yet. No stock moves.
     */
    public function registerSerials(Actor $actor, Warehouse $warehouse, Product $product, array|string $serials): int
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'stock.adjust', $warehouse)) {
            throw new AccessDenied;
        }
        if (! $product->track_serials) {
            throw new DomainException("{$product->name} does not track serial numbers.");
        }
        $list = SerialRegistry::normalise($serials);

        return $this->recorder->transaction(function () use ($actor, $warehouse, $product, $list) {
            $onHand = intdiv(Qty::toMilli((string) (DB::table('stock_levels')->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->lockForUpdate()->value('on_hand') ?? '0')), 1000);
            $numbered = DB::table('serial_numbers')->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->where('status', 'in_stock')->count();
            if ($list === [] || count($list) > $onHand - $numbered) {
                throw new DomainException(sprintf('%s: %d on hand here without a serial number; you entered %d.', $product->name, max(0, $onHand - $numbered), count($list)));
            }
            $rows = [];
            foreach ($list as $s) {
                $id = SerialRegistry::idFor($product->tenant_id, $product->id, $s);
                $row = DB::table('serial_numbers')->where('id', $id)->lockForUpdate()->first();
                if ($row && $row->status === 'in_stock') {
                    throw new DomainException("{$product->name} {$s} is already in stock.");
                }
                $values = ['status' => 'in_stock', 'warehouse_id' => $warehouse->id, 'updated_at' => now()];
                $row ? DB::table('serial_numbers')->where('id', $id)->update($values + ['version' => $row->version + 1])
                    : DB::table('serial_numbers')->insert($values + ['id' => $id, 'tenant_id' => $product->tenant_id, 'product_id' => $product->id, 'serial' => $s,
                        'origin_node' => (string) config('erp.node.id'), 'version' => 1, 'created_at' => now()]);
                $rows[] = (array) DB::table('serial_numbers')->where('id', $id)->first();
            }
            $this->recorder->audit->record($actor, 'stock.serials_registered', $product, ['warehouse' => [null, $warehouse->code], 'serials' => [null, count($list)]], null, $warehouse->branch_id);
            $this->recorder->outbox->publishRaw($actor, 'serial_batch', $rows[0]['id'], 1, 'stock.serials_registered', ['serials' => ['rows' => $rows, 'links' => []]]);

            return count($list);
        });
    }
}
