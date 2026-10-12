<?php

namespace App\Domain\Inventory;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Domain\Sync\ReviewQueue;
use App\Models\Bin;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\StockRequest;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Qty;
use Illuminate\Support\Facades\DB;

/**
 * Transfers between warehouses. Dispatch takes the stock out of the sending warehouse (valued at its average
 * cost); until it is received it is "in transit" and in neither warehouse. Receipt puts in what actually
 * arrived; any difference is flagged for review, never silently absorbed.
 */
final class TransferService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly StockLedger $ledger,
        private readonly ReviewQueue $reviews,
        private readonly StockBatches $batches,
    ) {}

    /**
     * @param  array{from_warehouse_id?: string, to_warehouse_id?: string, note?: ?string, lines?: list<array{product_uom_id: string, qty: string, serials?: array|string|null, to_product_id?: ?string}>}  $data
     * @param  ?StockRequest  $request  the approved stock request this transfer supplies; only then may goods cross between industries
     */
    public function saveDraft(Actor $actor, StockTransfer $t, array $data, ?StockRequest $request = null): StockTransfer
    {
        $from = Warehouse::with('branch')->findOrFail($data['from_warehouse_id'] ?? $t->from_warehouse_id);
        $to = Warehouse::with('branch')->findOrFail($data['to_warehouse_id'] ?? $t->to_warehouse_id);
        $this->require($actor, $from);
        if ($t->exists && $t->status !== 'draft') {
            throw new DomainException('Only a draft transfer can be changed.');
        }
        if ($from->id === $to->id) {
            throw new DomainException('Choose two different warehouses.');
        }
        $fromIndustry = $from->branch->industry_id;
        $toIndustry = $to->branch->industry_id;
        $request ??= $t->stock_request_id ? StockRequest::find($t->stock_request_id) : null;
        if ($fromIndustry && $toIndustry && $fromIndustry !== $toIndustry && ! $request) {
            throw new DomainException("{$from->branch->name} and {$to->branch->name} are in different industries. Ask with a stock request instead: both sides approve it and the goods move at cost.");
        }
        $lines = null;
        if (array_key_exists('lines', $data)) {
            $lines = [];
            foreach (array_values($data['lines'] ?? []) as $i => $r) {
                $unit = ProductUom::with('uom')->find($r['product_uom_id'] ?? null) ?? throw new DomainException('Choose a product and unit on every line.');
                $product = Product::findOrFail($unit->product_id);
                $qty = Qty::toMilli((string) ($r['qty'] ?? '0'));
                if ($qty <= 0) {
                    throw new DomainException("{$product->name}: enter a quantity above zero.");
                }
                if (! $product->track_stock) {
                    throw new DomainException("{$product->name} does not track stock.");
                }
                $this->assertCatalogue($product, $fromIndustry, $from->branch->name, 'send');
                $landing = $product;
                if (! empty($r['to_product_id']) && $r['to_product_id'] !== $product->id) {
                    if (! $request) {
                        throw new DomainException('Only a stock request between industries can receive goods as another product.');
                    }
                    $landing = Product::findOrFail($r['to_product_id']);
                    if (! $landing->track_stock || $landing->track_serials || $product->track_serials) {
                        throw new DomainException("{$product->name}: serial-numbered or untracked items cannot be swapped for another product.");
                    }
                }
                $this->assertCatalogue($landing, $toIndustry, $to->branch->name, 'stock');
                $serials = $product->track_serials ? SerialRegistry::normalise($r['serials'] ?? []) : null;
                if ($serials !== null && $serials !== [] && count($serials) * 1000 !== Qty::toBaseMilli($qty, Qty::factorMicro($unit->factor))) {
                    throw new DomainException("{$product->name}: one serial number per unit sent.");
                }
                $lines[] = ['line_no' => $i + 1, 'product_id' => $product->id, 'product_uom_id' => $unit->id, 'sku' => $product->sku, 'name' => $product->name,
                    'uom_code' => $unit->uom->code, 'factor' => $unit->factor, 'qty' => Qty::format($qty), 'serials' => $serials ?: null,
                    'to_product_id' => $landing->id === $product->id ? null : $landing->id];
            }
            if ($lines === []) {
                throw new DomainException('Add at least one line.');
            }
        }

        return $this->recorder->transaction(function () use ($actor, $t, $data, $from, $to, $lines, $request) {
            $isNew = ! $t->exists;
            $t->forceFill(['from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id, 'from_branch_id' => $from->branch_id, 'to_branch_id' => $to->branch_id,
                'note' => $data['note'] ?? $t->note]);
            if ($isNew) {
                $t->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'stock_transfer'), 'status' => 'draft', 'created_by_name' => $actor->name,
                    'stock_request_id' => $request?->id]);
            }
            $this->recorder->save($actor, $t, $isNew ? 'stock_transfer.created' : 'stock_transfer.updated', branchId: $from->branch_id);
            if ($lines !== null) {
                StockTransferLine::where('stock_transfer_id', $t->id)->delete();
                foreach ($lines as $l) {
                    StockTransferLine::create(['stock_transfer_id' => $t->id] + $l);
                }
                $this->recorder->audit->record($actor, 'stock_transfer.lines_changed', $t, ['lines' => [null, count($lines)]], null, $from->branch_id);
                $this->recorder->outbox->publish($actor, $t->fresh(), 'stock_transfer.lines_changed');
            }

            return $t->fresh('lines');
        });
    }

    public function dispatch(Actor $actor, StockTransfer $t): StockTransfer
    {
        $from = $t->fromWarehouse;
        $this->require($actor, $from);
        if ($t->status !== 'draft') {
            throw new DomainException("{$t->number} has already been dispatched or cancelled.");
        }

        return $this->recorder->transaction(function () use ($actor, $t, $from) {
            $t = StockTransfer::lockForUpdate()->findOrFail($t->id);
            $lines = $t->lines()->get();
            $entries = $lines->map(fn ($l) => ['product' => Product::findOrFail($l->product_id), 'unit' => ProductUom::findOrFail($l->product_uom_id),
                'qty_milli' => -Qty::toMilli((string) $l->qty), 'serials' => $l->serials])->all(); // serial-numbered units: the ones named on the line
            $this->ledger->post($actor, $from, 'transfer_out', $entries, "{$t->number} to ".$t->toWarehouse->name, 'stock_transfer', $t->id);
            foreach ($lines as $l) { // what the goods cost the sender travels with them (per base unit)
                $l->unit_cost_minor = DB::table('stock_levels')->where('warehouse_id', $from->id)->where('product_id', $l->product_id)->value('avg_cost_minor');
                $l->save();
            }
            $t->forceFill(['status' => 'in_transit', 'dispatched_at' => now(), 'dispatched_by_name' => $actor->name]);

            return $this->recorder->save($actor, $t->load('lines'), 'stock_transfer.dispatched', branchId: $t->from_branch_id);
        });
    }

    /**
     * @param  array<string, string>  $received  line id => quantity that arrived (in the line unit); missing lines = as sent
     */
    public function receive(Actor $actor, StockTransfer $t, array $received = [], ?string $toBinId = null, ?string $note = null): StockTransfer
    {
        $to = $t->toWarehouse;
        $this->require($actor, $to);
        if ($t->status !== 'in_transit') {
            throw new DomainException("{$t->number} is not in transit.");
        }
        if ($toBinId && ! Bin::where('warehouse_id', $to->id)->where('is_active', true)->whereKey($toBinId)->exists()) {
            throw new DomainException('That bin is not in the receiving warehouse.');
        }

        return $this->recorder->transaction(function () use ($actor, $t, $to, $received, $toBinId, $note) {
            $t = StockTransfer::lockForUpdate()->findOrFail($t->id);
            $entries = [];
            $diffs = [];
            foreach ($t->lines()->get() as $l) {
                $sent = Qty::toMilli((string) $l->qty);
                $got = array_key_exists($l->id, $received) ? Qty::toMilli((string) $received[$l->id]) : $sent;
                if ($got < 0) {
                    throw new DomainException('Received quantities cannot be negative.');
                }
                if ($got !== $sent) {
                    $diffs[] = "{$l->name}: sent ".Qty::format($sent).', received '.Qty::format($got)." {$l->uom_code}";
                }
                $unit = ProductUom::findOrFail($l->product_uom_id);
                $product = Product::findOrFail($l->product_id);
                // Between industries the goods land as the receiving catalogue's product: one base unit for one base unit.
                $landing = $l->to_product_id ? Product::findOrFail($l->to_product_id) : $product;
                $sentBatches = ! $product->track_batches ? collect() : DB::table('stock_movements as m')->join('stock_batches as b', 'b.id', '=', 'm.batch_id')
                    ->where('m.source_type', 'stock_transfer')->where('m.source_id', $t->id)->where('m.type', 'transfer_out')->where('m.product_id', $product->id)
                    ->groupBy('m.batch_id', 'b.batch_no', 'b.expires_on')->orderByRaw('b.expires_on ASC NULLS LAST')
                    ->selectRaw('m.batch_id, b.batch_no, b.expires_on, -SUM(m.qty_base) AS qty')->get();
                if ($got > 0 && ($sentBatches->isNotEmpty() || $landing->id !== $product->id)) {
                    // The batches that were sent arrive (earliest expiry first); anything extra joins the last of them.
                    // A mapped product gets batches of its own with the same number and expiry.
                    $base = ProductUom::where('product_id', $landing->id)->where('is_base', true)->firstOrFail();
                    $left = Qty::toBaseMilli($got, Qty::factorMicro($unit->factor));
                    $cost = StockProjection::costPerUnit($l->unit_cost_minor === null ? null : (string) $l->unit_cost_minor, '1');
                    if ($sentBatches->isEmpty()) {
                        $entries[] = ['product' => $landing, 'unit' => $base, 'qty_milli' => $left, 'bin_id' => $toBinId, 'unit_cost_minor' => $cost];
                    }
                    foreach ($sentBatches as $i => $sb) {
                        $q = $i === count($sentBatches) - 1 ? $left : min($left, Qty::toMilli((string) $sb->qty));
                        if ($q > 0) {
                            $batchId = $landing->id === $product->id ? $sb->batch_id
                                : ($landing->track_batches ? $this->batches->ensure($actor, $landing, $sb->batch_no, $sb->expires_on) : null);
                            $entries[] = ['product' => $landing, 'unit' => $base, 'qty_milli' => $q, 'bin_id' => $toBinId, 'unit_cost_minor' => $cost]
                                + ($batchId ? ['batch_id' => $batchId] : []);
                            $left -= $q;
                        }
                    }
                } elseif ($got > 0) {
                    // Serial-numbered: the units sent arrive (when fewer arrive, the first ones; the rest stay in transit and the transfer is flagged).
                    $serialsIn = $product->track_serials ? array_slice($l->serials ?? [], 0, intdiv(Qty::toBaseMilli($got, Qty::factorMicro($unit->factor)), 1000)) : null;
                    $entries[] = ['product' => $product, 'unit' => $unit, 'qty_milli' => $got, 'bin_id' => $toBinId, 'serials' => $serialsIn,
                        'unit_cost_minor' => StockProjection::costPerUnit($l->unit_cost_minor === null ? null : (string) $l->unit_cost_minor, (string) $unit->factor)];
                }
                $l->received_qty = Qty::format($got);
                $l->to_bin_id = $toBinId;
                $l->save();
            }
            if ($entries) {
                $this->ledger->post($actor, $to, 'transfer_in', $entries, "{$t->number} from ".$t->fromWarehouse->name, 'stock_transfer', $t->id);
            }
            $t->forceFill(['status' => 'received', 'received_at' => now(), 'received_by_name' => $actor->name, 'has_discrepancy' => $diffs !== [],
                'note' => trim(($t->note ? $t->note."\n" : '').($note ?? '')) ?: null]);
            $this->recorder->save($actor, $t->load('lines'), 'stock_transfer.received', branchId: $t->to_branch_id);
            if ($t->stock_request_id && ($req = StockRequest::lockForUpdate()->find($t->stock_request_id)) && $req->status === 'supplied') {
                $req->forceFill(['status' => 'received', 'closed_at' => now()]);
                $this->recorder->save($actor, $req, 'stock_request.received', branchId: $t->to_branch_id);
            }
            if ($diffs) {
                $this->reviews->add($actor, 'transfer_short', 'stock_transfer', $t->id,
                    "{$t->number} ({$t->fromWarehouse->code} → {$to->code}) arrived different from what was sent: ".implode('; ', $diffs), [], $t->to_branch_id);
            }

            return $t;
        });
    }

    public function cancel(Actor $actor, StockTransfer $t, string $reason): StockTransfer
    {
        $this->require($actor, $t->fromWarehouse);
        if ($t->status !== 'draft') {
            throw new DomainException('Only a draft transfer can be cancelled; one in transit must be received (enter 0 for anything that did not arrive).');
        }
        $t->forceFill(['status' => 'cancelled', 'cancelled_at' => now()]);

        return $this->recorder->save($actor, $t, 'stock_transfer.cancelled', $reason, $t->from_branch_id);
    }

    /** Base-unit quantities in transit to a warehouse, by product. */
    public static function inTransitTo(string $warehouseId): array
    {
        return DB::table('stock_transfer_lines')->join('stock_transfers', 'stock_transfers.id', '=', 'stock_transfer_lines.stock_transfer_id')
            ->where('stock_transfers.to_warehouse_id', $warehouseId)->where('stock_transfers.status', 'in_transit')
            ->groupBy('stock_transfer_lines.product_id')
            ->selectRaw('stock_transfer_lines.product_id, SUM(stock_transfer_lines.qty * stock_transfer_lines.factor) AS qty')
            ->pluck('qty', 'product_id')->map(fn ($q) => Qty::toMilli(number_format((float) $q, 3, '.', '')))->all();
    }

    /** Separate catalogues: a branch sends and stocks only its own industry's products, or shared ones. */
    private function assertCatalogue(Product $product, ?string $industryId, string $branchName, string $verb): void
    {
        if ($industryId && $product->industry_id && $product->industry_id !== $industryId) {
            $catalogue = DB::table('industries')->where('id', $product->industry_id)->value('name');
            throw new DomainException("{$product->name} is in the {$catalogue} catalogue, so {$branchName} cannot {$verb} it.");
        }
    }

    private function require(Actor $actor, Warehouse $warehouse): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'stock.transfer', $warehouse)) {
            throw new AccessDenied;
        }
    }
}
