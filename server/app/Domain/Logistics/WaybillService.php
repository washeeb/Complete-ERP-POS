<?php

namespace App\Domain\Logistics;

use App\Domain\Customers\CustomerService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Delivery;
use App\Models\GoodsReceipt;
use App\Models\StockTransfer;
use App\Models\SupplierReturn;
use App\Models\Waybill;
use App\Security\Authorizer;
use App\Support\Qty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Waybills for goods on the move: deliveries to customers, transfers between branches, returns to suppliers, and the
 * supplier's own waybill on goods received. Prepared from the document (its lines are copied as sent), dispatched with
 * the driver and vehicle, and closed with proof of delivery: by the office (signed copy or photo), or by the receiver
 * scanning the QR code on the printed waybill and confirming on their phone.
 */
final class WaybillService
{
    /** Who may handle a waybill, by what it carries. */
    public const PERMISSIONS = ['delivery' => 'sales.deliver', 'transfer' => 'stock.transfer', 'supplier_return' => 'stock.expiry', 'inbound' => 'stock.receive'];

    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly DocumentNumbers $numbers) {}

    /** The document a waybill can be made for, by kind and id. */
    public static function source(string $kind, string $id): Model
    {
        return match ($kind) {
            'delivery' => Delivery::with('lines', 'customer', 'branch')->findOrFail($id),
            'transfer' => StockTransfer::with('lines', 'fromWarehouse.branch', 'toWarehouse.branch')->findOrFail($id),
            'supplier_return' => SupplierReturn::with('lines.product.baseUom', 'supplier', 'warehouse.branch')->findOrFail($id),
            'inbound' => GoodsReceipt::with('lines', 'supplier', 'warehouse.branch')->findOrFail($id),
            default => throw new DomainException('Choose what the waybill is for.'),
        };
    }

    /**
     * @param  array{reference?: ?string, transporter?: ?string, driver_name?: ?string, driver_phone?: ?string, vehicle_no?: ?string, packages?: ?int,
     *   weight_kg?: ?float, to_address?: ?string, to_phone?: ?string, notes?: ?string}  $data
     */
    public function prepare(Actor $actor, string $kind, string $sourceId, array $data): Waybill
    {
        $src = self::source($kind, $sourceId);
        $branch = $this->branchOf($kind, $src);
        $this->require($actor, $kind, $branch);
        if ($kind !== 'inbound' && Waybill::where('source_type', Waybill::SOURCES[$kind])->where('source_id', $src->id)->where('status', '<>', 'cancelled')->exists()) {
            throw new DomainException('This document already has a waybill: open it, or cancel it first.');
        }
        if ($kind === 'inbound' && trim((string) ($data['reference'] ?? '')) === '') {
            throw new DomainException('Give the supplier\'s waybill number.');
        }
        [$fromName, $fromAddress, $toName, $toAddress, $toPhone] = $this->parties($kind, $src, $branch);
        $line = fn ($sku, $name, $qty, $unit) => ['sku' => $sku, 'name' => $name, 'qty' => Qty::format(Qty::toMilli((string) $qty)), 'unit' => $unit];
        $lines = match ($kind) {
            'supplier_return' => $src->lines->map(fn ($l) => $line($l->product->sku, $l->product->name.($l->batch_no ? " (batch {$l->batch_no})" : ''), $l->qty, $l->product->baseUom?->code))->all(),
            default => $src->lines->map(fn ($l) => $line($l->sku, $l->name, $l->qty, $l->uom_code))->all(),
        };
        $s = fn ($k, $max) => ($v = trim((string) ($data[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);
        $phone = $s('to_phone', 30) ?? $toPhone;

        return $this->recorder->transaction(function () use ($actor, $kind, $src, $branch, $data, $s, $fromName, $fromAddress, $toName, $toAddress, $phone, $lines) {
            $w = (new Waybill)->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'waybill'), 'branch_id' => $branch->id,
                'kind' => $kind, 'source_type' => Waybill::SOURCES[$kind], 'source_id' => $src->id, 'source_number' => $src->number,
                'reference' => $s('reference', 80), 'from_name' => $fromName, 'from_address' => $fromAddress, 'to_name' => $toName,
                'to_address' => $s('to_address', 255) ?? $toAddress, 'to_phone' => $phone ? (CustomerService::normalisePhone($phone) ?? $phone) : null,
                'transporter' => $s('transporter', 120), 'driver_name' => $s('driver_name', 120), 'driver_phone' => $s('driver_phone', 30), 'vehicle_no' => $s('vehicle_no', 30) ? strtoupper($s('vehicle_no', 30)) : null,
                'packages' => isset($data['packages']) && $data['packages'] !== '' ? max(1, (int) $data['packages']) : null,
                'weight_kg' => isset($data['weight_kg']) && $data['weight_kg'] !== '' ? max(0, (float) $data['weight_kg']) : null,
                'lines' => $lines, 'status' => $kind === 'inbound' ? 'delivered' : 'prepared', 'delivered_at' => $kind === 'inbound' ? now() : null,
                'received_by_name' => $kind === 'inbound' ? $actor->name : null, 'pod_via' => $kind === 'inbound' ? 'office' : null,
                'confirm_token' => $kind === 'inbound' ? null : Str::random(40), 'notes' => $s('notes', 500), 'created_by_name' => $actor->name]);
            $w->save();

            return $this->recorder->save($actor, $w, 'waybill.prepared', branchId: $branch->id);
        });
    }

    /** On the road: the driver, vehicle and packages as they leave. */
    public function dispatch(Actor $actor, Waybill $w, array $data = []): Waybill
    {
        $this->require($actor, $w->kind, $w->branch);
        if ($w->status !== 'prepared') {
            throw new DomainException('Only a prepared waybill can be dispatched.');
        }
        foreach (['transporter' => 120, 'driver_name' => 120, 'driver_phone' => 30, 'vehicle_no' => 30] as $k => $max) {
            if (trim((string) ($data[$k] ?? '')) !== '') {
                $w->{$k} = mb_substr(trim((string) $data[$k]), 0, $max);
            }
        }
        if (! $w->driver_name && ! $w->transporter) {
            throw new DomainException('Give the driver or the transport company.');
        }
        $w->forceFill(['status' => 'dispatched', 'dispatched_at' => now(), 'dispatched_by_name' => $actor->name, 'vehicle_no' => $w->vehicle_no ? strtoupper($w->vehicle_no) : null]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $w, 'waybill.dispatched', branchId: $w->branch_id));
    }

    /** Arrived: who received it, and the signed copy or a photo (proof of delivery). */
    public function deliver(Actor $actor, Waybill $w, string $receivedBy, ?string $podPath = null, ?string $note = null, ?string $phone = null, string $via = 'office'): Waybill
    {
        if ($via === 'office') {
            $this->require($actor, $w->kind, $w->branch);
        }
        if (! in_array($w->status, ['prepared', 'dispatched'], true)) {
            throw new DomainException('This waybill is '.strtolower(Waybill::STATUSES[$w->status]).'.');
        }
        if (trim($receivedBy) === '') {
            throw new DomainException('Give the name of the person who received the goods.');
        }
        $w->forceFill(['status' => 'delivered', 'delivered_at' => now(), 'received_by_name' => mb_substr(trim($receivedBy), 0, 120),
            'received_by_phone' => $phone ? mb_substr(trim($phone), 0, 30) : null, 'pod_path' => $podPath ?? $w->pod_path,
            'pod_note' => $note ? mb_substr(trim($note), 0, 500) : null, 'pod_via' => $via, 'confirm_token' => null]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $w, 'waybill.delivered', $note, $w->branch_id));
    }

    public function cancel(Actor $actor, Waybill $w, string $reason): Waybill
    {
        $this->require($actor, $w->kind, $w->branch);
        if ($w->status === 'delivered' || $w->status === 'cancelled' || trim($reason) === '') {
            throw new DomainException(trim($reason) === '' ? 'Give the reason.' : 'A delivered or cancelled waybill cannot be cancelled.');
        }
        $w->forceFill(['status' => 'cancelled', 'cancel_reason' => mb_substr(trim($reason), 0, 255), 'confirm_token' => null]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $w, 'waybill.cancelled', $reason, $w->branch_id));
    }

    private function branchOf(string $kind, Model $src): Branch
    {
        return match ($kind) {
            'delivery' => $src->branch,
            'transfer' => $src->fromWarehouse->branch,
            default => $src->warehouse->branch,
        };
    }

    /** @return array{0: string, 1: ?string, 2: string, 3: ?string, 4: ?string} from name and address, to name, address and phone */
    private function parties(string $kind, Model $src, Branch $branch): array
    {
        $here = [$branch->name, $branch->address];

        return match ($kind) {
            'delivery' => [...$here, $src->customer->name, $src->customer->address, $src->customer->phone],
            'transfer' => [...$here, $src->toWarehouse->branch->name.' · '.$src->toWarehouse->name, $src->toWarehouse->branch->address, $src->toWarehouse->branch->phone],
            'supplier_return' => [...$here, $src->supplier->name, $src->supplier->address, $src->supplier->phone],
            'inbound' => [$src->supplier->name, $src->supplier->address, $branch->name.' · '.$src->warehouse->name, $branch->address, $branch->phone],
        };
    }

    private function require(Actor $actor, string $kind, Branch $branch): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, self::PERMISSIONS[$kind], $branch)) {
            throw new AccessDenied;
        }
    }
}
