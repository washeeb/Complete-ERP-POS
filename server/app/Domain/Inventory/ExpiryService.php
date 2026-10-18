<?php

namespace App\Domain\Inventory;

use App\Domain\Payables\SupplierLedger;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Account;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnLine;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Qty;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Expired and near-expiry stock: the list of batches with their days left, and what to do with them. A pharmacist (or
 * storekeeper) prepares a disposal or a return to the supplier; someone else with "approve stock adjustments" approves
 * and posts it, whatever its value. Disposals keep the evidence the company asks for (Settings → Stock); controlled
 * drugs always need a witness.
 */
final class ExpiryService
{
    public const DISPOSAL_METHODS = ['incineration' => 'Incineration', 'fda_supervised' => 'Destroyed under FDA supervision',
        'waste_company' => 'Collected by an approved waste company', 'other' => 'Other (say how in the note)'];

    public const EVIDENCE = ['reason' => 'Reason only', 'method_witness' => 'Method and witness', 'method_witness_certificate' => 'Method, witness and certificate (upload)'];

    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly Settings $settings,
        private readonly DocumentNumbers $numbers, private readonly StockLedger $ledger, private readonly SupplierLedger $suppliers) {}

    /**
     * Batches holding stock, earliest expiry first, with days left, value and the supplier they came from.
     *
     * @param  array{warehouse_ids?: list<string>, within_days?: ?int, expired?: bool, controlled?: bool, supplier_id?: ?string, returnable?: bool}  $f
     * @return list<array<string, mixed>>
     */
    public function batches(string $tenantId, array $f = []): array
    {
        $today = CarbonImmutable::now(Tenant::find($tenantId)?->timezone ?: 'UTC')->startOfDay();
        $q = DB::table('stock_batch_levels as bl')->join('stock_batches as b', 'b.id', '=', 'bl.batch_id')->join('products as p', 'p.id', '=', 'bl.product_id')
            ->join('warehouses as w', 'w.id', '=', 'bl.warehouse_id')->leftJoin('uoms as u', 'u.id', '=', 'p.base_uom_id')
            ->leftJoin('stock_levels as sl', fn ($j) => $j->on('sl.warehouse_id', '=', 'bl.warehouse_id')->on('sl.product_id', '=', 'bl.product_id'))
            ->where('bl.tenant_id', $tenantId)->where('bl.on_hand', '>', 0)->whereNotNull('b.expires_on')
            ->when($f['warehouse_ids'] ?? null, fn ($w, $ids) => $w->whereIn('bl.warehouse_id', $ids))
            ->when(! empty($f['expired']), fn ($w) => $w->where('b.expires_on', '<', $today->toDateString()))
            ->when(empty($f['expired']) && ($f['within_days'] ?? null) !== null, fn ($w) => $w->where('b.expires_on', '<=', $today->addDays((int) $f['within_days'])->toDateString()))
            ->when(! empty($f['controlled']), fn ($w) => $w->where('p.drug_schedule', 'controlled'))
            ->orderBy('b.expires_on')->orderBy('p.name')
            ->select('bl.warehouse_id', 'w.code as warehouse', 'bl.batch_id', 'b.batch_no', 'b.expires_on', 'bl.product_id', 'p.sku', 'p.name', 'p.drug_schedule',
                'bl.on_hand', 'u.code as unit', 'sl.avg_cost_minor')
            ->selectSub(fn ($s) => $s->from('goods_receipt_lines as gl')->join('goods_receipts as gr', 'gr.id', '=', 'gl.goods_receipt_id')
                ->whereColumn('gl.product_id', 'p.id')->whereColumn('gl.batch_no', 'b.batch_no')->orderByDesc('gr.created_at')->limit(1)->select('gr.supplier_id'), 'supplier_id');
        $suppliers = Supplier::query()->get(['id', 'name', 'return_days_before_expiry'])->keyBy('id');
        $out = [];
        foreach ($q->get() as $r) {
            $days = (int) $today->diffInDays(CarbonImmutable::parse($r->expires_on), false);
            $supplier = $r->supplier_id ? $suppliers[$r->supplier_id] ?? null : null;
            $returnable = $supplier && $supplier->return_days_before_expiry !== null && $days >= (int) $supplier->return_days_before_expiry;
            if (($f['supplier_id'] ?? null) && $r->supplier_id !== $f['supplier_id']) {
                continue;
            }
            if (! empty($f['returnable']) && ! $returnable) {
                continue;
            }
            $qty = Qty::toMilli((string) $r->on_hand);
            $out[] = ['key' => "{$r->warehouse_id}|{$r->batch_id}", 'warehouse_id' => $r->warehouse_id, 'warehouse' => $r->warehouse, 'batch_id' => $r->batch_id,
                'batch_no' => $r->batch_no, 'expires_on' => substr((string) $r->expires_on, 0, 10), 'days_left' => $days, 'product_id' => $r->product_id, 'sku' => $r->sku,
                'name' => $r->name, 'controlled' => $r->drug_schedule === 'controlled', 'qty_milli' => $qty, 'qty' => Qty::format($qty), 'unit' => $r->unit,
                'value_minor' => (int) round($qty * (float) ($r->avg_cost_minor ?? 0) / 1000), 'supplier_id' => $r->supplier_id, 'supplier' => $supplier?->name,
                'returnable' => $returnable];
        }

        return $out;
    }

    /**
     * Prepare a disposal of whole batches: a stock adjustment of kind "disposal" (reason expired or damaged), waiting
     * for a second person to approve and post it.
     *
     * @param  list<string>  $keys  "warehouse_id|batch_id" of the batches (all in one warehouse)
     * @param  array{reason?: string, method?: ?string, disposed_on?: ?string, witness?: ?string, certificate_path?: ?string, note?: ?string}  $data
     */
    public function dispose(Actor $actor, array $keys, array $data): StockAdjustment
    {
        [$warehouse, $rows] = $this->selected($actor, $keys);
        $evidence = (string) $this->settings->get('stock.disposal_evidence', $actor->tenantId);
        $method = trim((string) ($data['method'] ?? ''));
        $witness = trim((string) ($data['witness'] ?? ''));
        $controlled = collect($rows)->contains('controlled', true);
        if ($evidence !== 'reason' && ! isset(self::DISPOSAL_METHODS[$method])) {
            throw new DomainException('Choose how the stock is disposed of.');
        }
        if (($evidence !== 'reason' || $controlled) && $witness === '') {
            throw new DomainException($controlled ? 'Controlled drugs are disposed of in front of a witness: give the witness\'s name.' : 'Give the name of the witness.');
        }
        if ($evidence === 'method_witness_certificate' && empty($data['certificate_path'])) {
            throw new DomainException('Attach the disposal certificate (or a photo of it).');
        }
        $reason = in_array($data['reason'] ?? 'expired', ['expired', 'damaged'], true) ? ($data['reason'] ?? 'expired') : 'expired';

        return $this->recorder->transaction(function () use ($actor, $warehouse, $rows, $data, $method, $witness, $reason) {
            $a = (new StockAdjustment)->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'stock_adjustment'),
                'branch_id' => $warehouse->branch_id, 'warehouse_id' => $warehouse->id, 'reason_code' => $reason, 'kind' => 'disposal',
                'disposal_method' => $method ?: null, 'disposed_on' => $data['disposed_on'] ?? now()->toDateString(), 'witness_name' => $witness ?: null,
                'certificate_path' => $data['certificate_path'] ?? null, 'note' => trim((string) ($data['note'] ?? '')) ?: null, 'status' => 'submitted',
                'created_by_name' => $actor->name, 'submitted_by' => $actor->id, 'submitted_by_name' => $actor->name, 'submitted_at' => now()]);
            $a->save();
            foreach (array_values($rows) as $i => $r) {
                StockAdjustmentLine::create(['stock_adjustment_id' => $a->id, 'line_no' => $i + 1, 'product_id' => $r['product_id'],
                    'product_uom_id' => $this->baseUnit($r['product_id']), 'mode' => 'remove', 'qty' => $r['qty'], 'batch_no' => $r['batch_no'],
                    'expires_on' => $r['expires_on'], 'reason_code' => $reason, 'note' => 'Disposal of batch '.$r['batch_no']]);
            }

            return $this->recorder->save($actor, $a->load('lines'), 'stock_disposal.prepared', $data['note'] ?? null, $warehouse->branch_id);
        });
    }

    /**
     * Prepare a return of whole batches to a supplier, waiting for a second person to approve and send it.
     *
     * @param  list<string>  $keys
     */
    public function prepareReturn(Actor $actor, array $keys, string $supplierId, string $settlement, string $reason, ?string $note = null): SupplierReturn
    {
        [$warehouse, $rows] = $this->selected($actor, $keys);
        $supplier = Supplier::find($supplierId) ?? throw new DomainException('Choose the supplier.');
        if (! isset(SupplierReturn::SETTLEMENTS[$settlement])) {
            throw new DomainException('Choose how the supplier settles the return.');
        }
        $reason = isset(SupplierReturn::REASONS[$reason]) ? $reason : 'expired';

        return $this->recorder->transaction(function () use ($actor, $warehouse, $rows, $supplier, $settlement, $reason, $note) {
            $r = (new SupplierReturn)->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'supplier_return'),
                'branch_id' => $warehouse->branch_id, 'warehouse_id' => $warehouse->id, 'supplier_id' => $supplier->id, 'status' => 'submitted',
                'settlement' => $settlement, 'reason' => $reason, 'note' => mb_substr(trim((string) $note), 0, 500) ?: null,
                'total_minor' => array_sum(array_column($rows, 'value_minor')), 'prepared_by' => $actor->id, 'prepared_by_name' => $actor->name]);
            $r->save();
            foreach (array_values($rows) as $i => $row) {
                SupplierReturnLine::create(['supplier_return_id' => $r->id, 'line_no' => $i + 1, 'product_id' => $row['product_id'], 'batch_id' => $row['batch_id'],
                    'batch_no' => $row['batch_no'], 'expires_on' => $row['expires_on'], 'qty' => $row['qty'], 'value_minor' => $row['value_minor']]);
            }

            return $this->recorder->save($actor, $r->load('lines'), 'supplier_return.prepared', $note, $warehouse->branch_id);
        });
    }

    /**
     * Approve and send a return: the stock leaves at average cost (into "goods returned to suppliers"); with a credit
     * note or replacement the supplier's account is credited now; with no credit it is written off; a refund waits for
     * the money (settle()).
     */
    public function postReturn(Actor $actor, SupplierReturn $r): SupplierReturn
    {
        $warehouse = $r->warehouse;
        $this->requireApprover($actor, $warehouse, $r->prepared_by);
        if ($r->status !== 'submitted') {
            throw new DomainException("{$r->number} is ".strtolower(SupplierReturn::STATUSES[$r->status]).'.');
        }

        return $this->recorder->transaction(function () use ($actor, $r, $warehouse) {
            $r = SupplierReturn::lockForUpdate()->findOrFail($r->id);
            $entries = [];
            foreach ($r->lines as $l) {
                $product = Product::findOrFail($l->product_id);
                $entries[] = ['product' => $product, 'unit' => ProductUom::with('uom')->findOrFail($this->baseUnit($l->product_id)),
                    'qty_milli' => -Qty::toMilli((string) $l->qty)] + ($l->batch_id ? ['batch_id' => $l->batch_id] : []);
            }
            $this->ledger->post($actor, $warehouse, 'supplier_return', $entries, "Returned to supplier ({$r->number})", 'supplier_return', $r->id);
            $total = (int) abs((int) DB::table('stock_movements')->where('source_type', 'supplier_return')->where('source_id', $r->id)
                ->selectRaw('COALESCE(SUM(ROUND(qty_entered * COALESCE(unit_cost_minor, 0))), 0)::bigint AS v')->value('v'));
            if (in_array($r->settlement, ['credit_note', 'replacement'], true) && $total > 0) {
                $this->suppliers->post($actor, $r->supplier, 'debit_note', -$total, Tenant::find($r->tenant_id)->currency_code, 'supplier_return', $r->id, $r->number,
                    branchId: $r->branch_id);
            }
            $r->forceFill(['status' => 'posted', 'total_minor' => $total, 'approved_by' => $actor->id, 'approved_by_name' => $actor->name, 'posted_at' => now()]);

            return $this->recorder->save($actor, $r, 'supplier_return.posted', branchId: $r->branch_id);
        });
    }

    /**
     * Record how the supplier settled: their credit note or replacement delivery reference, or the refund received
     * (into a cash, MoMo or bank account; a shortfall is written off as a stock loss when it is booked).
     *
     * @param  array{reference?: ?string, refund?: ?int, account_id?: ?string}  $data  refund in minor units
     */
    public function settle(Actor $actor, SupplierReturn $r, array $data): SupplierReturn
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, $r->settlement === 'refund' ? 'payables.pay' : 'stock.expiry', $r->warehouse)) {
            throw new AccessDenied($r->settlement === 'refund' ? 'Recording a refund needs the permission to pay suppliers.' : null);
        }
        if ($r->status !== 'posted' || $r->settled_at) {
            throw new DomainException('Only a return that was sent and not settled yet can be settled.');
        }
        $ref = mb_substr(trim((string) ($data['reference'] ?? '')), 0, 120);
        if ($ref === '') {
            throw new DomainException('Give the supplier\'s reference (credit note, delivery note or refund receipt).');
        }

        return $this->recorder->transaction(function () use ($actor, $r, $data, $ref) {
            $r->forceFill(['settlement_ref' => $ref, 'settled_at' => now(), 'settled_by_name' => $actor->name]);
            if ($r->settlement === 'refund') {
                $refund = (int) ($data['refund'] ?? 0);
                $account = Account::find($data['account_id'] ?? null) ?? throw new DomainException('Choose where the refund was received.');
                if ($refund < 0 || $refund > $r->total_minor) {
                    throw new DomainException('The refund is between 0 and the value returned.');
                }
                $r->forceFill(['refund_minor' => $refund, 'refund_account_id' => $account->id]); // booked by the automatic posting; a shortfall is a stock loss
            }

            return $this->recorder->save($actor, $r, 'supplier_return.settled', $ref, $r->branch_id);
        });
    }

    public function cancelReturn(Actor $actor, SupplierReturn $r, string $reason): SupplierReturn
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'stock.expiry', $r->warehouse)) {
            throw new AccessDenied;
        }
        if ($r->status !== 'submitted') {
            throw new DomainException('Only a return waiting for approval can be cancelled.');
        }
        $r->status = 'cancelled';

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $r, 'supplier_return.cancelled', $reason, $r->branch_id));
    }

    /** Approving disposals and returns: "approve stock adjustments", and never the person who prepared it. */
    public function requireApprover(Actor $actor, Warehouse $warehouse, ?string $preparedBy): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'stock.adjust.approve', $warehouse)) {
            throw new AccessDenied('Approving disposals and returns needs the permission to approve stock adjustments.');
        }
        if ($preparedBy && $preparedBy === $actor->id) {
            throw new DomainException('Someone other than the person who prepared it must approve it.', 'own_document');
        }
    }

    /** @return array{0: Warehouse, 1: list<array<string, mixed>>} the selected batches, all in one warehouse the person may work in */
    private function selected(Actor $actor, array $keys): array
    {
        $keys = array_values(array_unique(array_filter($keys)));
        if (! $keys) {
            throw new DomainException('Tick the batches first.');
        }
        $warehouseIds = array_unique(array_map(fn ($k) => explode('|', $k)[0], $keys));
        if (count($warehouseIds) !== 1) {
            throw new DomainException('Tick batches of one warehouse at a time.');
        }
        $warehouse = Warehouse::find($warehouseIds[0]) ?? throw new DomainException('Unknown warehouse.');
        if (! $actor->user || ! $this->authz->allows($actor->user, 'stock.expiry', $warehouse)) {
            throw new AccessDenied;
        }
        $rows = array_values(array_filter($this->batches($actor->tenantId, ['warehouse_ids' => [$warehouse->id]]), fn ($r) => in_array($r['key'], $keys, true)));
        if (count($rows) !== count($keys)) {
            throw new DomainException('Some of the ticked batches no longer hold stock: refresh the list.');
        }

        return [$warehouse, $rows];
    }

    private function baseUnit(string $productId): string
    {
        return (string) ProductUom::where('product_id', $productId)->where('is_base', true)->value('id');
    }
}
