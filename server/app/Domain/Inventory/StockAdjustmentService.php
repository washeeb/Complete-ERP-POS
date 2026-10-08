<?php

namespace App\Domain\Inventory;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Money;
use App\Support\Qty;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Stock adjustments (inventory adjustments): one document per warehouse and reason with any number of lines, each an
 * increase (+), a decrease (−) or "set to" a counted quantity. A draft can be edited and filled from a CSV file; posting
 * books the changes in the stock ledger in one go (nothing is overwritten — the ledger keeps every movement).
 *
 * Control: an adjustment worth more than the approval limit (Settings → Stock) must be approved and posted by someone
 * with "approve stock adjustments" other than the person who submitted it. Smaller ones can be posted by whoever may
 * adjust stock. "Set to" is worked out when posting, against what is on hand at that moment.
 */
final class StockAdjustmentService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly StockLedger $ledger,
        private readonly Settings $settings,
    ) {}

    /** A new draft. @param array{reason_code: string, reference?: ?string, note?: ?string} $data */
    public function create(Actor $actor, Warehouse $warehouse, array $data): StockAdjustment
    {
        $this->require($actor, 'stock.adjust', $warehouse);
        $reason = (string) ($data['reason_code'] ?? '');
        if (! array_key_exists($reason, InventoryService::ADJUSTMENT_REASONS)) {
            throw new DomainException('Choose the reason for the adjustment.');
        }

        return $this->recorder->transaction(function () use ($actor, $warehouse, $data, $reason) {
            $a = (new StockAdjustment)->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'stock_adjustment'),
                'branch_id' => $warehouse->branch_id, 'warehouse_id' => $warehouse->id, 'reason_code' => $reason,
                'reference' => mb_substr(trim((string) ($data['reference'] ?? '')), 0, 60) ?: null, 'note' => trim((string) ($data['note'] ?? '')) ?: null,
                'status' => 'draft', 'created_by_name' => $actor->name]);

            return $this->recorder->save($actor, $a, 'stock_adjustment.created', branchId: $warehouse->branch_id);
        });
    }

    /**
     * Save a draft from the screen: the header and all its lines (the lines replace the earlier ones).
     *
     * @param  array{warehouse_id?: string, reason_code?: string, reference?: ?string, note?: ?string, lines?: list<array>}  $data
     */
    public function saveDraft(Actor $actor, StockAdjustment $a, array $data): StockAdjustment
    {
        return $this->recorder->transaction(function () use ($actor, $a, $data) {
            $a = $a->exists ? $this->update($actor, $a, array_intersect_key($data, array_flip(['reason_code', 'reference', 'note'])))
                : $this->create($actor, Warehouse::findOrFail($data['warehouse_id'] ?? null), $data);
            foreach (StockAdjustmentLine::where('stock_adjustment_id', $a->id)->pluck('id') as $id) {
                $this->removeLine($actor, $a, $id);
            }
            foreach (array_values($data['lines'] ?? []) as $i => $l) {
                $unit = ProductUom::find($l['product_uom_id'] ?? null) ?? throw new DomainException('Line '.($i + 1).': choose the product and unit.');
                try {
                    $this->addLine($actor, $a, ['product_id' => $unit->product_id] + $l);
                } catch (DomainException $e) {
                    throw new DomainException('Line '.($i + 1).': '.$e->getMessage());
                }
            }

            return $a->fresh('lines');
        });
    }

    /** Change the header of a draft. */
    public function update(Actor $actor, StockAdjustment $a, array $data): StockAdjustment
    {
        $this->draft($actor, $a);
        if (isset($data['reason_code']) && ! array_key_exists($data['reason_code'], InventoryService::ADJUSTMENT_REASONS)) {
            throw new DomainException('Choose the reason for the adjustment.');
        }
        $a->forceFill(array_intersect_key($data, array_flip(['reason_code', 'reference', 'note'])));

        return $this->recorder->save($actor, $a, 'stock_adjustment.updated', branchId: $a->branch_id);
    }

    /**
     * Add a line to a draft.
     *
     * @param  array{product_id?: string, sku?: string, product_uom_id?: ?string, uom_code?: ?string, mode: string, qty: string, unit_cost?: ?string, batch_no?: ?string, expires_on?: ?string, serials?: array|string|null, reason_code?: ?string, note?: ?string}  $l
     */
    public function addLine(Actor $actor, StockAdjustment $a, array $l): StockAdjustmentLine
    {
        $this->draft($actor, $a);
        $product = ! empty($l['product_id']) ? Product::find($l['product_id']) : Product::where('sku', strtoupper(trim((string) ($l['sku'] ?? ''))))->first();
        if (! $product) {
            throw new DomainException('Unknown product'.(! empty($l['sku']) ? " {$l['sku']}" : '').'.');
        }
        if (! $product->track_stock) {
            throw new DomainException("{$product->name} does not track stock.");
        }
        $unit = match (true) {
            ! empty($l['product_uom_id']) => ProductUom::with('uom')->where('product_id', $product->id)->find($l['product_uom_id']),
            ! empty($l['uom_code']) => ProductUom::with('uom')->where('product_id', $product->id)->whereHas('uom', fn ($q) => $q->where('code', strtoupper(trim($l['uom_code']))))->first(),
            default => ProductUom::with('uom')->where('product_id', $product->id)->where('is_base', true)->first(),
        } ?? throw new DomainException("{$product->sku}: that unit is not one of the product's units.");
        $mode = strtolower(trim((string) $l['mode']));
        if (! array_key_exists($mode, StockAdjustment::MODES)) {
            throw new DomainException('Choose increase, decrease or set to.');
        }
        $milli = Qty::toMilli(trim((string) $l['qty']));
        if ($milli < 0 || ($milli === 0 && $mode !== 'set')) {
            throw new DomainException("{$product->sku}: enter a quantity above zero".($mode === 'set' ? ' (or 0 to set the stock to nothing)' : '').'.');
        }
        if ($milli % (10 ** (3 - $unit->uom->decimals)) !== 0) {
            throw new DomainException("{$product->sku}: {$unit->uom->name} allows {$unit->uom->decimals} decimal place(s).");
        }
        $serials = $product->track_serials ? SerialRegistry::normalise(is_array($l['serials'] ?? null) ? $l['serials'] : preg_split('/[|,\s]+/', (string) ($l['serials'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)) : [];
        if ($product->track_serials && ($mode === 'set' || count($serials) * 1000 !== Qty::toBaseMilli($milli, Qty::factorMicro($unit->factor)))) {
            throw new DomainException("{$product->sku}: serial-numbered — use increase or decrease and list one serial number per unit.");
        }
        $batch = trim((string) ($l['batch_no'] ?? '')) ?: null;
        if ($mode === 'set' && $product->track_batches) {
            throw new DomainException("{$product->sku}: tracks batches — use increase or decrease with the batch number.");
        }
        if ($mode === 'add' && $product->track_batches && ! $batch) {
            throw new DomainException("{$product->sku}: tracks batches — give the batch number (and expiry) of what comes in.");
        }
        $reason = trim((string) ($l['reason_code'] ?? '')) ?: null;
        if ($reason && ! array_key_exists($reason, InventoryService::ADJUSTMENT_REASONS)) {
            throw new DomainException("{$product->sku}: unknown reason \"{$reason}\".");
        }
        $cost = isset($l['unit_cost']) && trim((string) $l['unit_cost']) !== '' ? Money::toMinor((string) $l['unit_cost'], Tenant::find($a->tenant_id)->currency_code) : null;
        if ($cost !== null && $cost < 0) {
            throw new DomainException("{$product->sku}: the unit cost cannot be negative.");
        }

        return $this->recorder->transaction(function () use ($actor, $a, $product, $unit, $mode, $milli, $serials, $batch, $l, $reason, $cost) {
            $line = StockAdjustmentLine::create(['stock_adjustment_id' => $a->id, 'line_no' => (int) StockAdjustmentLine::where('stock_adjustment_id', $a->id)->max('line_no') + 1,
                'product_id' => $product->id, 'product_uom_id' => $unit->id, 'mode' => $mode, 'qty' => Qty::format($milli), 'unit_cost_minor' => $mode === 'add' ? $cost : null,
                'batch_no' => $batch, 'expires_on' => ! empty($l['expires_on']) ? $l['expires_on'] : null, 'serials' => $serials ?: null, 'reason_code' => $reason,
                'note' => mb_substr(trim((string) ($l['note'] ?? '')), 0, 255) ?: null]);
            $a->touch();
            $this->recorder->audit->record($actor, 'stock_adjustment.line_added', $a, [$product->sku => [null, StockAdjustment::MODES[$mode].' '.Qty::format($milli).' '.$unit->uom->code]], null, $a->branch_id);

            return $line;
        });
    }

    public function removeLine(Actor $actor, StockAdjustment $a, string $lineId): void
    {
        $this->draft($actor, $a);
        $this->recorder->transaction(function () use ($actor, $a, $lineId) {
            $line = StockAdjustmentLine::where('stock_adjustment_id', $a->id)->findOrFail($lineId);
            $line->delete();
            $this->recorder->audit->record($actor, 'stock_adjustment.line_removed', $a, ['line' => [$line->line_no, null]], null, $a->branch_id);
        });
    }

    public function submit(Actor $actor, StockAdjustment $a): StockAdjustment
    {
        $this->draft($actor, $a);
        if (! $a->lines()->exists()) {
            throw new DomainException('Add at least one line.');
        }
        $a->forceFill(['status' => 'submitted', 'submitted_by' => $actor->id, 'submitted_by_name' => $actor->name, 'submitted_at' => now()]);

        return $this->recorder->save($actor, $a->load('lines'), 'stock_adjustment.submitted', branchId: $a->branch_id);
    }

    /** Send a submitted adjustment back to draft (to correct it). */
    public function reopen(Actor $actor, StockAdjustment $a, string $reason): StockAdjustment
    {
        $this->require($actor, 'stock.adjust', $a->warehouse);
        if ($a->status !== 'submitted') {
            throw new DomainException('Only an adjustment waiting for approval can be sent back.');
        }
        $a->forceFill(['status' => 'draft', 'submitted_by' => null, 'submitted_by_name' => null, 'submitted_at' => null]);

        return $this->recorder->save($actor, $a, 'stock_adjustment.reopened', $reason, $a->branch_id);
    }

    /** What posting would do now: per line the stock before, the change and after (base units), and the value at average cost. */
    public function preview(StockAdjustment $a): array
    {
        $out = [];
        $value = 0;
        foreach ($a->lines()->with('product', 'unit.uom')->get() as $l) {
            $level = DB::table('stock_levels')->where('warehouse_id', $a->warehouse_id)->where('product_id', $l->product_id)->first(['on_hand', 'avg_cost_minor']);
            $before = Qty::toMilli((string) ($level->on_hand ?? '0'));
            $qtyBase = Qty::toBaseMilli(Qty::toMilli((string) $l->qty), Qty::factorMicro($l->unit->factor));
            $change = match ($l->mode) {
                'add' => $qtyBase,
                'remove' => -$qtyBase,
                default => $qtyBase - $before,
            };
            $cost = $l->mode === 'add' && $l->unit_cost_minor !== null ? $l->unit_cost_minor / max(1, Qty::factorMicro($l->unit->factor) / 1_000_000) : (float) ($level->avg_cost_minor ?? 0);
            $lineValue = (int) round($change * $cost / 1000);
            $value += $lineValue;
            $out[] = ['line' => $l, 'before' => $before, 'change' => $change, 'after' => $before + $change, 'value' => $lineValue];
        }

        return ['lines' => $out, 'value' => $value, 'gross' => array_sum(array_map(fn ($x) => abs($x['value']), $out))];
    }

    /** Book the adjustment in the stock ledger. */
    public function post(Actor $actor, StockAdjustment $a): StockAdjustment
    {
        $warehouse = $a->warehouse;
        $this->require($actor, 'stock.adjust', $warehouse);
        if (! in_array($a->status, ['draft', 'submitted'], true)) {
            throw new DomainException("{$a->number} is already ".strtolower(StockAdjustment::STATUSES[$a->status]).'.');
        }
        if (! $a->lines()->exists()) {
            throw new DomainException('Add at least one line.');
        }
        $limit = (int) $this->settings->get('stock.adjustment_approval_minor', $a->tenant_id);
        $gross = $this->preview($a)['gross'];
        if ($gross > $limit) {
            if (! $this->authz->allows($actor->user, 'stock.adjust.approve', $warehouse)) {
                throw new DomainException('This adjustment is worth '.Money::format($gross, Tenant::find($a->tenant_id)->currency_code)
                    .', above the approval limit: submit it for approval by a manager.', 'approval_needed');
            }
            if ($a->submitted_by === $actor->id || ($a->status === 'draft')) {
                throw new DomainException('Above the approval limit, someone other than the person who prepared it must approve it: submit it first.', 'own_document');
            }
        }

        return $this->recorder->transaction(function () use ($actor, $a, $warehouse) {
            $a = StockAdjustment::lockForUpdate()->findOrFail($a->id);
            $preview = $this->preview($a); // against stock on hand now, under the lock
            $entries = [];
            foreach ($preview['lines'] as $p) {
                $l = $p['line'];
                $l->forceFill(['on_hand_before' => Qty::format($p['before']), 'change_qty' => Qty::format($p['change'])])->save();
                if ($p['change'] === 0) {
                    continue;
                }
                $batch = $l->batch_no ? ['batch_id' => app(StockBatches::class)->ensure($actor, $l->product, $l->batch_no, $l->expires_on?->toDateString())] : [];
                $base = ProductUom::where('product_id', $l->product_id)->where('is_base', true)->firstOrFail();
                $entries[] = ['product' => $l->product, 'unit' => $l->mode === 'set' ? $base : $l->unit,
                    'qty_milli' => $l->mode === 'set' ? $p['change'] : ($l->mode === 'add' ? 1 : -1) * Qty::toMilli((string) $l->qty),
                    'unit_cost_minor' => $l->unit_cost_minor, 'serials' => $l->serials] + $batch;
            }
            $reason = InventoryService::ADJUSTMENT_REASONS[$a->reason_code].($a->reference ? " ({$a->reference})" : '');
            if ($entries) {
                $this->ledger->post($actor, $warehouse, 'adjustment', $entries, "Stock adjustment {$a->number}: {$reason}", 'stock_adjustment', $a->id);
            }
            $a->forceFill(['status' => 'posted', 'posted_by_name' => $actor->name, 'posted_at' => now(), 'value_minor' => $preview['value']]);

            return $this->recorder->save($actor, $a->load('lines'), 'stock_adjustment.posted', branchId: $a->branch_id);
        });
    }

    public function cancel(Actor $actor, StockAdjustment $a, string $reason): StockAdjustment
    {
        $this->require($actor, 'stock.adjust', $a->warehouse);
        if (! in_array($a->status, ['draft', 'submitted'], true) || trim($reason) === '') {
            throw new DomainException(trim($reason) === '' ? 'Give the reason.' : 'Only a draft or an adjustment waiting for approval can be cancelled.');
        }
        $a->forceFill(['status' => 'cancelled', 'cancel_reason' => mb_substr(trim($reason), 0, 255)]);

        return $this->recorder->save($actor, $a, 'stock_adjustment.cancelled', $reason, $a->branch_id);
    }

    private function draft(Actor $actor, StockAdjustment $a): void
    {
        $this->require($actor, 'stock.adjust', $a->warehouse);
        if ($a->status !== 'draft') {
            throw new DomainException("{$a->number} is no longer a draft.");
        }
    }

    private function require(Actor $actor, string $permission, Warehouse $warehouse): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, $permission, $warehouse)) {
            throw new AccessDenied;
        }
    }
}
