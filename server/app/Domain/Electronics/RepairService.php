<?php

namespace App\Domain\Electronics;

use App\Domain\Inventory\SerialRegistry;
use App\Domain\Inventory\StockLedger;
use App\Domain\Modules\ModuleService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\RepairJob;
use App\Models\RepairJobLine;
use App\Models\Sale;
use App\Models\SerialNumber;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Qty;

/**
 * Repair job cards (Electronics, owner decision 2026-10-19). A device comes in with its fault; a serial number we sold
 * tells whether it is still under warranty. Parts and labour are added at their price-list prices and paid at the
 * till when the customer collects (the sale moves the parts out of stock); under warranty nothing is charged and the
 * parts are written off to the repair. A job can go back to the supplier (RMA) and return.
 */
final class RepairService
{
    public const TRANSITIONS = [
        'received' => ['diagnosing', 'waiting_parts', 'repairing', 'at_supplier', 'ready', 'cancelled'],
        'diagnosing' => ['waiting_parts', 'repairing', 'at_supplier', 'ready', 'cancelled'],
        'waiting_parts' => ['repairing', 'at_supplier', 'ready', 'cancelled'],
        'repairing' => ['waiting_parts', 'at_supplier', 'ready', 'cancelled'],
        'at_supplier' => ['repairing', 'ready', 'cancelled'],
        'ready' => ['repairing'],
    ];

    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly ModuleService $modules,
        private readonly PriceResolver $prices,
        private readonly StockLedger $ledger,
    ) {}

    /** @param array{branch_id: string, customer_id?: ?string, customer_name?: ?string, phone?: ?string, serial?: ?string, device?: ?string, fault?: string, accessories?: ?string, estimate_minor?: ?int, promised_on?: ?string} $data */
    public function open(Actor $actor, array $data): RepairJob
    {
        $branch = Branch::findOrFail($data['branch_id'] ?? null);
        $this->require($actor, $branch);
        $customer = ! empty($data['customer_id']) ? Customer::find($data['customer_id']) ?? throw new DomainException('That customer is not known here.') : null;
        $name = trim((string) ($data['customer_name'] ?? $customer?->name ?? ''));
        $fault = trim((string) ($data['fault'] ?? ''));
        // A serial number we know: the product, and whether it is still under warranty.
        $serial = ($s = SerialRegistry::normalise([(string) ($data['serial'] ?? '')])[0] ?? null) ? SerialNumber::where('serial', $s)->orderByDesc('sold_at')->first() : null;
        $device = trim((string) ($data['device'] ?? '')) ?: ($serial ? Product::find($serial->product_id)?->name : '');
        if ($name === '' || $fault === '' || $device === '') {
            throw new DomainException('Enter the customer, the device and what is wrong with it.');
        }

        return $this->recorder->transaction(function () use ($actor, $branch, $customer, $name, $fault, $device, $serial, $data, $s) {
            $job = (new RepairJob)->forceFill([
                'tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'repair_job'), 'branch_id' => $branch->id,
                'customer_id' => $customer?->id ?? $serial?->customer_id, 'customer_name' => mb_substr($name, 0, 120),
                'phone' => preg_replace('/[^0-9+]/', '', (string) ($data['phone'] ?? $customer?->phone ?? '')) ?: null,
                'product_id' => $serial?->product_id, 'serial_number_id' => $serial?->id, 'serial' => $s, 'device' => mb_substr($device, 0, 160),
                'fault' => mb_substr($fault, 0, 2000), 'accessories' => isset($data['accessories']) ? mb_substr(trim((string) $data['accessories']), 0, 255) ?: null : null,
                'status' => 'received', 'in_warranty' => (bool) $serial?->inWarranty(), 'estimate_minor' => isset($data['estimate_minor']) ? max(0, (int) $data['estimate_minor']) : null,
                'promised_on' => $data['promised_on'] ?? null, 'received_at' => now(),
                'log' => [['at' => now()->toIso8601String(), 'by' => $actor->name, 'status' => 'received', 'note' => $serial?->inWarranty() ? 'Under warranty until '.$serial->warranty_until->toDateString() : null]],
            ]);

            return $this->recorder->save($actor, $job, 'repair_job.opened', branchId: $branch->id);
        });
    }

    /** Move the job along (diagnosing, waiting for parts, repairing, at the supplier, ready…), with a note for the log. */
    public function setStatus(Actor $actor, RepairJob $job, string $status, ?string $note = null, ?string $supplierId = null, ?string $rmaReference = null): RepairJob
    {
        $this->require($actor, Branch::findOrFail($job->branch_id));
        if (! in_array($status, self::TRANSITIONS[$job->status] ?? [], true)) {
            throw new DomainException('A job that is '.strtolower(RepairJob::STATUSES[$job->status] ?? $job->status).' cannot become '.strtolower(RepairJob::STATUSES[$status] ?? $status).'.', 'wrong_status');
        }
        if ($status === 'at_supplier') {
            Supplier::find($supplierId) ?? throw new DomainException('Choose the supplier it goes back to.');
        }
        $job->forceFill(['status' => $status, 'log' => [...($job->log ?? []), ['at' => now()->toIso8601String(), 'by' => $actor->name, 'status' => $status,
            'note' => $note ? mb_substr(trim($note), 0, 500) : null]]]
            + ($status === 'at_supplier' ? ['supplier_id' => $supplierId, 'rma_reference' => $rmaReference ? mb_substr(trim($rmaReference), 0, 80) : null] : [])
            + ($status === 'ready' ? ['ready_at' => now()] : []));

        return $this->recorder->save($actor, $job, "repair_job.{$status}", $note, $job->branch_id);
    }

    /**
     * Parts and labour on the job, priced from the default price list (no charge under warranty).
     *
     * @param  list<array{product_uom_id: string, qty: string}>  $lines
     */
    public function setLines(Actor $actor, RepairJob $job, array $lines): RepairJob
    {
        $this->require($actor, Branch::findOrFail($job->branch_id));
        if (! in_array($job->status, RepairJob::OPEN, true)) {
            throw new DomainException('This job is closed.');
        }
        $list = PriceList::where('is_default', true)->firstOrFail();
        $rows = [];
        foreach (array_values($lines) as $l) {
            $unit = ProductUom::with('uom')->find($l['product_uom_id'] ?? null) ?? throw new DomainException('Choose the part or labour on every line.');
            $product = Product::findOrFail($unit->product_id);
            $qty = Qty::toMilli((string) ($l['qty'] ?? '0'));
            if ($qty <= 0) {
                throw new DomainException("{$product->name}: enter the quantity.");
            }
            $price = $job->in_warranty ? 0 : ($this->prices->resolve($list, $product->id, $unit->id, $qty, now())?->amount_minor
                ?? throw new DomainException("{$product->name} has no {$list->name} price."));
            $rows[] = ['product_id' => $product->id, 'product_uom_id' => $unit->id, 'name' => $product->name, 'qty' => Qty::format($qty), 'unit_price_minor' => $price];
        }

        return $this->recorder->transaction(function () use ($actor, $job, $rows) {
            RepairJobLine::where('repair_job_id', $job->id)->delete();
            foreach ($rows as $r) {
                RepairJobLine::create(['repair_job_id' => $job->id] + $r);
            }
            $job->forceFill(['version' => $job->version + 1])->save();
            $this->recorder->audit->record($actor, 'repair_job.lines_changed', $job, ['lines' => [null, count($rows)]], null, $job->branch_id);
            $this->recorder->outbox->publish($actor, $job->fresh(), 'repair_job.lines_changed');

            return $job->fresh('lines');
        });
    }

    /**
     * The customer collects without paying at the till: under warranty (parts written off to the repair) or when there is
     * nothing to charge. A job with charges is collected by paying for it at the till instead.
     */
    public function handOver(Actor $actor, RepairJob $job, Warehouse $warehouse): RepairJob
    {
        $this->require($actor, Branch::findOrFail($job->branch_id));
        if ($job->status !== 'ready') {
            throw new DomainException('Only a job that is ready can be handed over.', 'wrong_status');
        }
        $lines = $job->lines()->get();
        if ($lines->sum(fn ($l) => $l->unit_price_minor) > 0) {
            throw new DomainException('This job has charges: take payment at the till to hand it over.', 'payment_needed');
        }

        return $this->recorder->transaction(function () use ($actor, $job, $warehouse, $lines) {
            $parts = $lines->map(fn ($l) => ['product' => Product::findOrFail($l->product_id), 'unit' => ProductUom::findOrFail($l->product_uom_id),
                'qty_milli' => -Qty::toMilli((string) $l->qty)])->filter(fn ($e) => $e['product']->track_stock)->values()->all();
            if ($parts) {
                $this->ledger->post($actor, $warehouse, 'adjustment', $parts, mb_substr(($job->in_warranty ? 'Warranty repair' : 'Repair')." {$job->number}", 0, 255), 'repair_job', $job->id);
            }
            $job->forceFill(['status' => 'collected', 'collected_at' => now(), 'log' => [...($job->log ?? []), ['at' => now()->toIso8601String(), 'by' => $actor->name, 'status' => 'collected', 'note' => null]]]);

            return $this->recorder->save($actor, $job, 'repair_job.collected', branchId: $job->branch_id);
        });
    }

    /** A till sale paid for the job's parts and labour: the job is collected (called inside the sale's transaction). */
    public function paidBySale(Actor $actor, string $jobId, Sale $sale, bool $offline, array &$flags): void
    {
        $job = RepairJob::lockForUpdate()->find($jobId);
        if (! $job || $job->branch_id !== $sale->branch_id || ! in_array($job->status, RepairJob::OPEN, true)) {
            if (! $offline) {
                throw new DomainException('That repair job is not open at this branch.', 'repair_closed');
            }
            $flags[] = ['repair_conflict', 'Paid offline for repair job '.($job?->number ?? $jobId).' that was not open'];

            return;
        }
        $job->forceFill(['status' => 'collected', 'collected_at' => now(), 'sale_id' => $sale->id,
            'log' => [...($job->log ?? []), ['at' => now()->toIso8601String(), 'by' => $actor->name, 'status' => 'collected', 'note' => "Paid on {$sale->receipt_no}"]]]);
        $this->recorder->save($actor, $job, 'repair_job.collected', branchId: $job->branch_id, payload: ['sale_id' => $sale->id]);
    }

    private function require(Actor $actor, Branch $branch): void
    {
        if (! $this->modules->isEnabled($branch, 'electronics')) {
            throw new DomainException('The Electronics module is not on at this branch.', 'module_disabled');
        }
        if (! $actor->user || ! $this->authz->allows($actor->user, 'electronics.repairs', $branch)) {
            throw new AccessDenied;
        }
    }
}
