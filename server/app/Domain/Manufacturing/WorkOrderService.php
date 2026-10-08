<?php

namespace App\Domain\Manufacturing;

use App\Domain\Inventory\StockBatches;
use App\Domain\Inventory\StockLedger;
use App\Domain\Modules\ModuleService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Bom;
use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\QcInspection;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WorkCentre;
use App\Models\WorkOrder;
use App\Models\WorkOrderCost;
use App\Models\WorkOrderMaterial;
use App\Security\Authorizer;
use App\Support\Qty;
use Illuminate\Support\Facades\DB;

/**
 * Work orders, at actual cost (owner decision 2026-10-19).
 *
 * Draft → **released** (the BOM's materials and operations are copied onto the order) → **in production** (materials
 * issued, output reported) → **finished**. While it runs, everything it costs collects in *work in progress*:
 *
 * - materials leave the warehouse at their average cost: issued by hand, or **backflushed** (issued automatically for
 *   what is reported, at the BOM quantity plus its expected loss); unused materials can be returned;
 * - labour and overhead: each operation's minutes × its work centre's hourly cost, charged automatically at the planned
 *   time when output is reported (backflushed operations) or as the time is recorded;
 * - by-products come out of it at their average cost.
 *
 * Good output goes into stock carrying the cost collected so far, spread over the units still expected, so the order's
 * last good units take whatever is left: the product enters stock at what it actually cost. Scrapped units add nothing
 * to stock; their cost stays with the good units. When a quality check is required, good output waits for a quality
 * inspector, who passes it into stock or fails it (scrap). Cost still in work in progress when the order is finished
 * (time recorded after the last output, materials not returned) goes to production cost differences.
 */
final class WorkOrderService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly StockLedger $ledger,
        private readonly StockBatches $batches,
        private readonly ModuleService $modules,
    ) {}

    /** @param array{warehouse_id: string, product_id: string, qty: string, bom_id?: ?string, due_on?: ?string, customer_order_id?: ?string, parent_work_order_id?: ?string, note?: ?string} $data */
    public function create(Actor $actor, array $data): WorkOrder
    {
        $warehouse = Warehouse::find($data['warehouse_id'] ?? null) ?? throw new DomainException('Choose the warehouse materials come from and output goes to.');
        $this->require($actor, 'manufacturing.produce', $warehouse);
        $product = Product::find($data['product_id'] ?? null) ?? throw new DomainException('Choose the product to make.');
        $bom = ! empty($data['bom_id']) ? Bom::where('product_id', $product->id)->find($data['bom_id']) : BomService::activeFor($product->id);
        if (! $bom || $bom->status !== 'active') {
            throw new DomainException("{$product->name} has no bill of materials in use.");
        }
        $qty = Qty::toMilli((string) ($data['qty'] ?? '0'));
        if ($qty <= 0) {
            throw new DomainException('Enter how many to make.');
        }
        if (! empty($data['customer_order_id']) && ! CustomerOrder::whereKey($data['customer_order_id'])->exists()) {
            throw new DomainException('That customer order does not exist.');
        }

        return $this->recorder->transaction(function () use ($actor, $data, $warehouse, $product, $bom, $qty) {
            $wo = (new WorkOrder)->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'work_order'),
                'branch_id' => $warehouse->branch_id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'bom_id' => $bom->id,
                'customer_order_id' => $data['customer_order_id'] ?? null, 'parent_work_order_id' => $data['parent_work_order_id'] ?? null,
                'status' => 'draft', 'qty_planned' => Qty::format($qty), 'qc_required' => $bom->qc_required, 'due_on' => $data['due_on'] ?? null,
                'note' => $data['note'] ?? null, 'created_by_name' => $actor->name]);

            return $this->recorder->save($actor, $wo, 'work_order.created', branchId: $warehouse->branch_id)->refresh(); // with the table's defaults
        });
    }

    /** Change a draft: quantity, due date, note. */
    public function update(Actor $actor, WorkOrder $wo, array $data): WorkOrder
    {
        $this->require($actor, 'manufacturing.produce', $wo->warehouse);
        if ($wo->status !== 'draft') {
            throw new DomainException("{$wo->number} is released; only a draft can be changed.");
        }
        if (array_key_exists('qty', $data)) {
            $qty = Qty::toMilli((string) $data['qty']);
            if ($qty <= 0) {
                throw new DomainException('Enter how many to make.');
            }
            $wo->qty_planned = Qty::format($qty);
        }
        $wo->fill(array_intersect_key($data, array_flip(['due_on', 'note'])));

        return $this->recorder->save($actor, $wo, 'work_order.updated', branchId: $wo->branch_id);
    }

    /**
     * Release for production: the BOM in use is copied onto the order (later BOM changes do not touch it). With
     * $subAssemblies, a draft work order is raised for every sub-assembly the warehouse is short of.
     *
     * @return WorkOrder the released order (sub-assembly orders link to it through parent_work_order_id)
     */
    public function release(Actor $actor, WorkOrder $wo, bool $subAssemblies = false): WorkOrder
    {
        $this->require($actor, 'manufacturing.produce', $wo->warehouse);
        if ($wo->status !== 'draft') {
            throw new DomainException("{$wo->number} is already released.");
        }
        $bom = Bom::with('lines.unit')->findOrFail($wo->bom_id);
        if ($bom->status !== 'active') {
            $bom = BomService::activeFor($wo->product_id)?->load('lines.unit') ?? throw new DomainException('The product has no bill of materials in use any more.');
        }
        $planned = Qty::toMilli((string) $wo->qty_planned);
        $batch = Qty::toMilli((string) $bom->output_qty);

        return $this->recorder->transaction(function () use ($actor, $wo, $bom, $planned, $batch, $subAssemblies) {
            $wo = WorkOrder::lockForUpdate()->findOrFail($wo->id);
            $wo->materials()->delete();
            foreach ($bom->lines as $i => $l) {
                // Base units per base unit of output, with the expected loss on materials.
                $perBatch = bcmul((string) $l->qty, (string) $l->unit->factor, 9);
                if ($l->kind === 'material') {
                    $perBatch = bcmul($perBatch, bcadd('1', bcdiv((string) $l->scrap_bp, '10000', 6), 6), 9);
                }
                $perUnit = bcdiv(bcmul($perBatch, '1000', 9), (string) $batch, 6);
                WorkOrderMaterial::create(['work_order_id' => $wo->id, 'line_no' => $i + 1, 'kind' => $l->kind, 'product_id' => $l->product_id,
                    'per_unit' => $perUnit, 'planned_qty' => Qty::format(self::times($perUnit, $planned)), 'backflush' => $l->backflush]);
            }
            $rates = WorkCentre::whereIn('id', array_column($bom->operations ?? [], 'work_centre_id'))->pluck('hourly_rate_minor', 'id');
            $wo->forceFill(['bom_id' => $bom->id, 'qc_required' => $bom->qc_required, 'status' => 'released', 'released_by_name' => $actor->name, 'released_at' => now(),
                'operations' => array_map(fn ($op) => $op + ['rate_minor' => (int) ($rates[$op['work_centre_id']] ?? 0),
                    'run_per_unit' => round($op['run_minutes'] * 1000 / $batch, 4), 'planned_minutes' => round($op['setup_minutes'] + $op['run_minutes'] * $planned / $batch, 2),
                    'actual_minutes' => 0, 'setup_charged' => false], $bom->operations ?? [])]);
            $this->recorder->save($actor, $wo->load('materials'), 'work_order.released', branchId: $wo->branch_id);
            if ($subAssemblies) {
                $this->raiseSubAssemblies($actor, $wo);
            }

            return $wo;
        });
    }

    /** Issue materials by hand: [work_order_material_id => qty in base units]. */
    public function issue(Actor $actor, WorkOrder $wo, array $quantities, ?string $note = null): WorkOrder
    {
        return $this->run($actor, $wo, function (WorkOrder $wo) use ($actor, $quantities, $note) {
            $lines = [];
            foreach ($quantities as $id => $qty) {
                $milli = Qty::toMilli((string) ($qty ?: '0'));
                if ($milli < 0) {
                    throw new DomainException('Issue quantities cannot be negative; use Return materials.');
                }
                if ($milli > 0) {
                    $lines[] = [$this->material($wo, $id, 'material'), $milli];
                }
            }
            if (! $lines) {
                throw new DomainException('Enter what is being issued.');
            }
            $this->consume($actor, $wo, $lines, 'production_issue', 'Issued'.($note ? ": {$note}" : ''));

            return 'work_order.issued';
        });
    }

    /** Unused materials back to the warehouse, at the cost they were issued at. */
    public function returnMaterials(Actor $actor, WorkOrder $wo, array $quantities, ?string $note = null): WorkOrder
    {
        return $this->run($actor, $wo, function (WorkOrder $wo) use ($actor, $quantities, $note) {
            $lines = [];
            foreach ($quantities as $id => $qty) {
                $milli = Qty::toMilli((string) ($qty ?: '0'));
                if ($milli <= 0) {
                    continue;
                }
                $m = $this->material($wo, $id, 'material');
                if ($milli > Qty::toMilli((string) $m->issued_qty)) {
                    throw new DomainException("{$m->product->name}: only ".Qty::format(Qty::toMilli((string) $m->issued_qty)).' was issued to this order.');
                }
                $lines[] = [$m, $milli];
            }
            if (! $lines) {
                throw new DomainException('Enter what is coming back.');
            }
            $this->consume($actor, $wo, $lines, 'production_return', 'Returned'.($note ? ": {$note}" : ''));

            return 'work_order.returned';
        });
    }

    /** Time worked on an operation that is not backflushed (or extra time on one that is). */
    public function recordTime(Actor $actor, WorkOrder $wo, int $seq, string|float $minutes, ?string $note = null): WorkOrder
    {
        return $this->run($actor, $wo, function (WorkOrder $wo) use ($actor, $seq, $minutes, $note) {
            $minutes = round((float) $minutes, 2);
            if ($minutes <= 0 || $minutes > 100000) {
                throw new DomainException('Enter the minutes worked.');
            }
            $ops = $wo->operations;
            $i = array_search($seq, array_column($ops, 'seq'), true);
            if ($i === false) {
                throw new DomainException('That operation is not on this work order.');
            }
            $this->charge($actor, $wo, $ops[$i], $minutes, $note);
            $ops[$i]['actual_minutes'] = round($ops[$i]['actual_minutes'] + $minutes, 2);
            $wo->operations = $ops;

            return 'work_order.time_recorded';
        });
    }

    /**
     * Report output: $good units made and $scrap units spoilt. Backflushed materials and operations are charged for both;
     * good units go into stock (or wait for the quality check).
     */
    public function reportOutput(Actor $actor, WorkOrder $wo, string $good, string $scrap = '0', ?string $batchNo = null, ?string $expiresOn = null, ?string $note = null): WorkOrder
    {
        return $this->run($actor, $wo, function (WorkOrder $wo) use ($actor, $good, $scrap, $batchNo, $expiresOn, $note) {
            $g = Qty::toMilli($good ?: '0');
            $s = Qty::toMilli($scrap ?: '0');
            if ($g < 0 || $s < 0 || $g + $s === 0) {
                throw new DomainException('Enter the good and/or scrapped quantity made.');
            }
            $made = $g + $s;
            $backflush = [];
            foreach ($wo->materials->where('kind', 'material')->where('backflush', true) as $m) {
                $q = self::times((string) $m->per_unit, $made);
                if ($q > 0) {
                    $backflush[] = [$m, $q];
                }
            }
            if ($backflush) {
                $this->consume($actor, $wo, $backflush, 'production_issue', "Backflushed for {$wo->number}");
            }
            $ops = $wo->operations;
            foreach ($ops as $i => $op) {
                if (! $op['backflush']) {
                    continue;
                }
                $minutes = round(($op['setup_charged'] ? 0 : $op['setup_minutes']) + $op['run_per_unit'] * $made / 1000, 2);
                if ($minutes > 0) {
                    $this->charge($actor, $wo, $op, $minutes, 'Backflushed');
                    $ops[$i]['actual_minutes'] = round($op['actual_minutes'] + $minutes, 2);
                }
                $ops[$i]['setup_charged'] = true;
            }
            $wo->operations = $ops;
            $wo->qty_scrapped = Qty::format(Qty::toMilli((string) $wo->qty_scrapped) + $s);
            if ($g > 0) {
                $wo->qc_required
                    ? $wo->qty_awaiting_qc = Qty::format(Qty::toMilli((string) $wo->qty_awaiting_qc) + $g)
                    : $this->putAway($actor, $wo, $g, $batchNo, $expiresOn);
            }
            if ($note) {
                $wo->note = trim(($wo->note ? $wo->note."\n" : '').now()->format('j M H:i')." {$actor->name}: {$note}");
            }

            return 'work_order.output_reported';
        });
    }

    /**
     * Quality check of output waiting for one: passed units go into stock, failed units are scrapped.
     *
     * @param  list<array{check: string, result: string, value?: ?string}>  $checks
     */
    public function inspect(Actor $actor, WorkOrder $wo, string $passed, string $failed, array $checks = [], ?string $note = null, ?string $batchNo = null, ?string $expiresOn = null): QcInspection
    {
        $this->require($actor, 'manufacturing.qc', $wo->warehouse);
        $p = Qty::toMilli($passed ?: '0');
        $f = Qty::toMilli($failed ?: '0');
        $waiting = Qty::toMilli((string) $wo->qty_awaiting_qc);
        if ($p < 0 || $f < 0 || $p + $f === 0 || $p + $f > $waiting) {
            throw new DomainException('Passed plus failed must be above zero and at most the '.Qty::format($waiting).' waiting for inspection.');
        }
        if ($f > 0 && trim((string) $note) === '') {
            throw new DomainException('Say why units failed.');
        }
        $checks = array_values(array_filter(array_map(fn ($c) => ['check' => mb_substr(trim((string) ($c['check'] ?? '')), 0, 120),
            'result' => ($c['result'] ?? 'pass') === 'fail' ? 'fail' : 'pass', 'value' => isset($c['value']) ? mb_substr(trim((string) $c['value']), 0, 60) : null], $checks), fn ($c) => $c['check'] !== ''));

        return $this->recorder->transaction(function () use ($actor, $wo, $p, $f, $checks, $note, $batchNo, $expiresOn) {
            $wo = WorkOrder::with('materials.product')->lockForUpdate()->findOrFail($wo->id);
            $qc = (new QcInspection)->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'qc_inspection'),
                'work_order_id' => $wo->id, 'branch_id' => $wo->branch_id, 'product_id' => $wo->product_id, 'qty_passed' => Qty::format($p), 'qty_failed' => Qty::format($f),
                'checks' => $checks, 'note' => $note, 'batch_no' => $batchNo ?: null, 'inspector_id' => $actor->id, 'inspector_name' => $actor->name]);
            $this->recorder->save($actor, $qc, 'qc_inspection.recorded', branchId: $wo->branch_id);
            $wo->qty_awaiting_qc = Qty::format(Qty::toMilli((string) $wo->qty_awaiting_qc) - $p - $f);
            $wo->qty_scrapped = Qty::format(Qty::toMilli((string) $wo->qty_scrapped) + $f);
            if ($p > 0) {
                $this->putAway($actor, $wo, $p, $batchNo, $expiresOn);
            }
            $this->recorder->save($actor, $wo, 'work_order.inspected', "{$qc->number}: ".Qty::format($p).' passed, '.Qty::format($f).' failed', $wo->branch_id);

            return $qc;
        });
    }

    /** Finish the order: cost still in work in progress goes to production cost differences. */
    public function finish(Actor $actor, WorkOrder $wo, ?string $reason = null): WorkOrder
    {
        return $this->run($actor, $wo, function (WorkOrder $wo) use ($actor, $reason) {
            if (Qty::toMilli((string) $wo->qty_awaiting_qc) > 0) {
                throw new DomainException('Output is still waiting for its quality check.');
            }
            $made = Qty::toMilli((string) $wo->qty_done) + Qty::toMilli((string) $wo->qty_scrapped);
            if ($made < Qty::toMilli((string) $wo->qty_planned) && trim((string) $reason) === '') {
                throw new DomainException('Fewer were made than planned; give the reason for finishing short.');
            }
            $left = $wo->wipMinor();
            if ($left !== 0) {
                $this->recorder->save($actor, (new WorkOrderCost)->forceFill(['tenant_id' => $actor->tenantId, 'work_order_id' => $wo->id, 'branch_id' => $wo->branch_id,
                    'kind' => 'variance', 'amount_minor' => $left, 'note' => 'Left in work in progress at finish', 'actor_name' => $actor->name, 'occurred_at' => now()]),
                    'work_order.variance', branchId: $wo->branch_id);
                $wo->variance_minor += $left;
            }
            $wo->forceFill(['status' => 'done', 'closed_by_name' => $actor->name, 'closed_at' => now(), 'close_reason' => $reason ?: null]);

            return 'work_order.finished';
        });
    }

    /** Cancel an order nothing has been done on yet. */
    public function cancel(Actor $actor, WorkOrder $wo, string $reason): WorkOrder
    {
        $this->require($actor, 'manufacturing.produce', $wo->warehouse);
        if (trim($reason) === '') {
            throw new DomainException('Give the reason.');
        }
        if (! in_array($wo->status, ['draft', 'released'], true) || (int) $wo->material_minor !== 0 || (int) $wo->labour_minor !== 0
            || Qty::toMilli((string) $wo->qty_done) + Qty::toMilli((string) $wo->qty_scrapped) + Qty::toMilli((string) $wo->qty_awaiting_qc) > 0
            || $wo->materials()->where('issued_qty', '<>', 0)->exists()) {
            throw new DomainException('Work has started on this order; return the materials and finish it instead.');
        }
        $wo->forceFill(['status' => 'cancelled', 'closed_by_name' => $actor->name, 'closed_at' => now(), 'close_reason' => $reason]);

        return $this->recorder->save($actor, $wo, 'work_order.cancelled', $reason, $wo->branch_id);
    }

    /** $perUnit (base per base unit) × $milli of output → base milli, rounded half up. */
    public static function times(string $perUnit, int $milli): int
    {
        $v = bcmul($perUnit, (string) $milli, 6);

        return (int) bcadd($v, bccomp($v, '0', 6) >= 0 ? '0.5' : '-0.5', 0);
    }

    /** Value of a movement as the books see it (quantity × cost per entered unit, rounded). */
    public static function valueOf(StockMovement $m): int
    {
        return (int) round((float) bcmul((string) $m->qty_entered, (string) ((int) $m->unit_cost_minor), 3));
    }

    /** Runs a step on a released or running order in one transaction, then saves it with the step's audit action. */
    private function run(Actor $actor, WorkOrder $wo, callable $step): WorkOrder
    {
        $this->require($actor, 'manufacturing.produce', $wo->warehouse);

        return $this->recorder->transaction(function () use ($actor, $wo, $step) {
            $wo = WorkOrder::with('materials.product')->lockForUpdate()->findOrFail($wo->id);
            if (! in_array($wo->status, ['released', 'in_progress'], true)) {
                throw new DomainException($wo->status === 'draft' ? "Release {$wo->number} first." : "{$wo->number} is ".mb_strtolower(WorkOrder::STATUSES[$wo->status]).'.');
            }
            $action = $step($wo);
            if ($wo->status === 'released' && $action !== 'work_order.finished') {
                $wo->forceFill(['status' => 'in_progress', 'started_at' => now()]);
            }
            $wo->updated_at = now(); // material lines changed: the snapshot goes out again

            return $this->recorder->save($actor, $wo->load('materials'), $action, branchId: $wo->branch_id);
        });
    }

    private function material(WorkOrder $wo, string $id, string $kind): WorkOrderMaterial
    {
        $m = $wo->materials->firstWhere('id', $id);
        if (! $m || $m->kind !== $kind) {
            throw new DomainException('That material is not on this work order.');
        }

        return $m;
    }

    /**
     * Materials out of (issue) or back into (return) the warehouse, in base units; their value moves into or out of
     * work in progress.
     *
     * @param  list<array{0: WorkOrderMaterial, 1: int}>  $lines
     */
    private function consume(Actor $actor, WorkOrder $wo, array $lines, string $type, string $reason): void
    {
        $sign = $type === 'production_issue' ? -1 : 1;
        $entries = [];
        foreach ($lines as [$m, $milli]) {
            $entry = ['product' => $m->product, 'unit' => self::baseUnit($m->product_id), 'qty_milli' => $sign * $milli];
            if ($type === 'production_return') { // back at what this order paid for it, so work in progress clears exactly
                $issued = StockMovement::where('source_type', 'work_order')->where('source_id', $wo->id)->where('product_id', $m->product_id)
                    ->whereIn('type', ['production_issue', 'production_return'])->get();
                $qty = -$issued->sum(fn ($x) => Qty::toMilli((string) $x->qty_base));
                $value = -$issued->sum(fn ($x) => self::valueOf($x));
                $entry['unit_cost_minor'] = $qty > 0 ? (int) round($value * 1000 / $qty) : null;
            }
            $entries[] = $entry;
        }
        $movements = $this->ledger->post($actor, $wo->warehouse, $type, $entries, "{$wo->number}: {$reason}", 'work_order', $wo->id);
        $wo->material_minor -= array_sum(array_map(fn ($mv) => self::valueOf($mv), $movements));
        foreach ($lines as [$m, $milli]) {
            $m->issued_qty = Qty::format(Qty::toMilli((string) $m->issued_qty) - $sign * $milli); // issued net of returns
            $m->save();
        }
    }

    /** Labour and overhead for minutes on an operation, into work in progress. */
    private function charge(Actor $actor, WorkOrder $wo, array $op, float $minutes, ?string $note): void
    {
        $amount = (int) round($minutes * $op['rate_minor'] / 60);
        $this->recorder->save($actor, (new WorkOrderCost)->forceFill(['tenant_id' => $actor->tenantId, 'work_order_id' => $wo->id, 'branch_id' => $wo->branch_id,
            'kind' => 'labour', 'operation_seq' => $op['seq'], 'work_centre_id' => $op['work_centre_id'], 'minutes' => $minutes, 'amount_minor' => $amount,
            'note' => $note ? mb_substr("{$op['name']}: {$note}", 0, 255) : $op['name'], 'actor_name' => $actor->name, 'occurred_at' => now()]),
            'work_order.labour', branchId: $wo->branch_id);
        $wo->labour_minor += $amount;
    }

    /** Good units into stock at the cost collected so far, spread over the good units still expected; by-products first. */
    private function putAway(Actor $actor, WorkOrder $wo, int $good, ?string $batchNo, ?string $expiresOn): void
    {
        $byProducts = [];
        foreach ($wo->materials->where('kind', 'by_product') as $m) {
            $q = self::times((string) $m->per_unit, $good);
            if ($q > 0) {
                $byProducts[] = [$m, $q];
            }
        }
        if ($byProducts) {
            $movements = $this->ledger->post($actor, $wo->warehouse, 'by_product', array_map(fn ($b) => ['product' => $b[0]->product, 'unit' => self::baseUnit($b[0]->product_id),
                'qty_milli' => $b[1]], $byProducts), "{$wo->number}: by-product", 'work_order', $wo->id);
            $wo->by_product_minor += array_sum(array_map(fn ($mv) => self::valueOf($mv), $movements));
            foreach ($byProducts as [$m, $q]) {
                $m->issued_qty = Qty::format(Qty::toMilli((string) $m->issued_qty) + $q);
                $m->save();
            }
        }
        $expected = max(Qty::toMilli((string) $wo->qty_planned) - Qty::toMilli((string) $wo->qty_done) - Qty::toMilli((string) $wo->qty_scrapped)
            - Qty::toMilli((string) $wo->qty_awaiting_qc), $good);
        $cost = (int) round(max(0, $wo->wipMinor()) * 1000 / $expected); // per base unit
        $product = Product::findOrFail($wo->product_id);
        $batch = $product->track_batches ? ['batch_id' => $this->batches->ensure($actor, $product, trim((string) $batchNo) ?: $wo->number, $expiresOn ?: null)] : [];
        [$movement] = $this->ledger->post($actor, $wo->warehouse, 'production_output', [['product' => $product, 'unit' => self::baseUnit($product->id), 'qty_milli' => $good,
            'unit_cost_minor' => $cost] + $batch], "{$wo->number}: made", 'work_order', $wo->id);
        $wo->output_minor += self::valueOf($movement);
        $wo->qty_done = Qty::format(Qty::toMilli((string) $wo->qty_done) + $good);
    }

    /** Draft orders for sub-assemblies the warehouse is short of, linked to their parent order. */
    private function raiseSubAssemblies(Actor $actor, WorkOrder $wo): void
    {
        foreach ($wo->materials()->where('kind', 'material')->get() as $m) {
            if (! BomService::activeFor($m->product_id)) {
                continue;
            }
            $onHand = Qty::toMilli((string) (DB::table('stock_levels')->where('warehouse_id', $wo->warehouse_id)->where('product_id', $m->product_id)->value('on_hand') ?? '0'));
            $short = Qty::toMilli((string) $m->planned_qty) - max(0, $onHand);
            if ($short > 0) {
                $this->create($actor, ['warehouse_id' => $wo->warehouse_id, 'product_id' => $m->product_id, 'qty' => Qty::format($short),
                    'due_on' => $wo->due_on?->toDateString(), 'parent_work_order_id' => $wo->id, 'note' => "Sub-assembly for {$wo->number}"]);
            }
        }
    }

    public static function baseUnit(string $productId): ProductUom
    {
        return ProductUom::with('uom')->where('product_id', $productId)->where('is_base', true)->firstOrFail();
    }

    private function require(Actor $actor, string $permission, Warehouse $warehouse): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, $permission, $warehouse)) {
            throw new AccessDenied;
        }
        if (! $this->modules->isEnabled(Branch::findOrFail($warehouse->branch_id), 'manufacturing')) {
            throw new DomainException('Switch on Manufacturing for this branch first (System → Modules).', 'module_disabled');
        }
    }
}
