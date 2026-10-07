<?php

namespace App\Domain\Inventory;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Money;
use App\Support\Qty;

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
    public function adjust(Actor $actor, Warehouse $warehouse, Product $product, ProductUom $unit, string $qty, string $type, string $reasonCode, ?string $note = null, ?string $unitCost = null,
        ?string $batchNo = null, ?string $expiresOn = null): StockMovement
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

        return $this->recorder->transaction(function () use ($actor, $warehouse, $product, $unit, $milli, $type, $reason, $cost, $batchNo, $expiresOn) {
            // Batch-tracked: a named batch (new or existing); without one, stock out is taken earliest expiry first.
            $batch = $product->track_batches && trim((string) $batchNo) !== '' ? ['batch_id' => app(StockBatches::class)->ensure($actor, $product, (string) $batchNo, $expiresOn)] : [];
            [$movement] = $this->ledger->post($actor, $warehouse, $type, [
                ['product' => $product, 'unit' => $unit, 'qty_milli' => $milli, 'unit_cost_minor' => $cost] + $batch,
            ], $reason, 'manual', null);
            $this->recorder->audit->record($actor, "stock.{$type}", $product, [
                'warehouse' => [null, $warehouse->code],
                'qty' => [null, Qty::format($milli).' '.$unit->uom->code],
            ], $reason, $warehouse->branch_id);

            return $movement;
        });
    }
}
