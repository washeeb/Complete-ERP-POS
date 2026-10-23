<?php

namespace App\Domain\Inventory;

use App\Domain\Purchasing\GoodsReceiptService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Bin;
use App\Models\Device;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\PurchaseOrder;
use App\Models\StockCount;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Security\Authorizer;
use Illuminate\Support\Facades\DB;

/**
 * Warehouse handheld actions, once each (owner request 2026-10-23). The same action may arrive twice — sent from the
 * offline queue after the answer to an earlier try was lost — so each carries the handheld's own id: done the first
 * time, its answer kept (handheld_ops), and that answer returned for any repeat. Used by the queued (offline) path
 * through the till sync and by the handheld's direct calls. Every action is checked against the person who did it, at
 * the handheld's branch, and goes through the same services as the back office.
 */
final class HandheldOps
{
    public const OPS = ['wms_receive', 'wms_putaway', 'wms_transfer', 'wms_count_start', 'wms_count_line', 'wms_count_submit'];

    public function __construct(
        private readonly Authorizer $authz,
        private readonly GoodsReceiptService $receipts,
        private readonly BinService $bins,
        private readonly TransferService $transfers,
        private readonly StockCountService $counts,
    ) {}

    /** @return array{status: string, number?: string, detail?: string} 'accepted' the first time, 'duplicate' after */
    public function run(Device $device, User $user, string $op, string $opId, array $p): array
    {
        if (! in_array($op, self::OPS, true)) {
            throw new DomainException('Unknown handheld action.', 'malformed');
        }
        if ($device->kind !== 'handheld') {
            throw new DomainException('Only a warehouse handheld can do this.', 'malformed');
        }
        $done = DB::table('handheld_ops')->where('id', $opId)->first();
        if ($done) {
            if ($done->device_id !== $device->id) {
                throw new DomainException('That action belongs to another handheld.', 'malformed');
            }

            return ['status' => 'duplicate'] + (json_decode($done->result, true) ?: []);
        }
        $actor = Actor::user($user, 'handheld', $device->id);
        $result = match ($op) {
            'wms_receive' => $this->receive($device, $actor, $opId, $p),
            'wms_putaway' => $this->putAway($device, $actor, $p),
            'wms_transfer' => $this->transfer($device, $actor, $p),
            'wms_count_start' => $this->countStart($device, $actor, $opId, $p),
            'wms_count_line' => $this->countLine($device, $actor, $p),
            'wms_count_submit' => $this->countSubmit($device, $actor, $p),
        };
        DB::table('handheld_ops')->insertOrIgnore(['id' => $opId, 'tenant_id' => $device->tenant_id, 'device_id' => $device->id, 'user_id' => $user->id,
            'op' => $op, 'result' => json_encode($result), 'done_at' => now()]);

        return ['status' => 'accepted'] + $result;
    }

    /** A warehouse of the handheld's branch where the person holds the permission. */
    private function warehouse(Device $device, Actor $actor, ?string $id, string $permission): Warehouse
    {
        $w = Warehouse::where('branch_id', $device->branch_id)->find($id) ?? throw new DomainException('That warehouse is not at this handheld’s branch.');
        if (! $this->authz->allows($actor->user, $permission, $w)) {
            throw new AccessDenied;
        }

        return $w;
    }

    private function receive(Device $device, Actor $actor, string $opId, array $p): array
    {
        $po = PurchaseOrder::find($p['purchase_order_id'] ?? null) ?? throw new DomainException('That purchase order is not known here.');
        $this->warehouse($device, $actor, $po->warehouse_id, 'stock.receive');
        $grn = $this->receipts->receive($actor, $po, ['client_id' => $p['client_id'] ?? $opId, 'supplier_ref' => $p['supplier_ref'] ?? null, 'note' => $p['note'] ?? null,
            'lines' => (array) ($p['lines'] ?? [])]);

        return ['number' => $grn->number, 'detail' => $grn->status === 'held' ? 'More than ordered: waiting for a manager.' : 'Received into stock.'];
    }

    private function putAway(Device $device, Actor $actor, array $p): array
    {
        $w = $this->warehouse($device, $actor, $p['warehouse_id'] ?? null, 'stock.receive');
        $unit = ProductUom::find($p['product_uom_id'] ?? null) ?? throw new DomainException('That product unit is not known here.');
        $this->bins->putAway($actor, $w, Product::findOrFail($unit->product_id), $unit, (string) ($p['qty'] ?? '0'), (string) ($p['from_bin_id'] ?? ''), (string) ($p['to_bin_id'] ?? ''));

        return ['detail' => 'Moved.'];
    }

    private function transfer(Device $device, Actor $actor, array $p): array
    {
        $t = StockTransfer::find($p['transfer_id'] ?? null) ?? throw new DomainException('That transfer is not known here.');
        $this->warehouse($device, $actor, $t->to_warehouse_id, 'stock.transfer');
        if ($t->status !== 'received') {
            $t = $this->transfers->receive($actor, $t, array_map('strval', (array) ($p['lines'] ?? [])), $p['to_bin_id'] ?? null, $p['note'] ?? null);
        }

        return ['number' => $t->number, 'detail' => $t->has_discrepancy ? 'Received; the difference is reported for review.' : 'Received.'];
    }

    private function countStart(Device $device, Actor $actor, string $opId, array $p): array
    {
        $w = $this->warehouse($device, $actor, $p['warehouse_id'] ?? null, 'stock.count');
        $bin = ($p['bin_id'] ?? null) ? Bin::where('warehouse_id', $w->id)->findOrFail($p['bin_id']) : null;
        $c = $this->counts->start($actor, $w, $bin, id: (string) ($p['id'] ?? $opId));

        return ['number' => $c->number, 'id' => $c->id];
    }

    private function countLine(Device $device, Actor $actor, array $p): array
    {
        $c = StockCount::find($p['count_id'] ?? null) ?? throw new DomainException('That count is not known here.');
        $this->warehouse($device, $actor, $c->warehouse_id, 'stock.count');
        $unit = ProductUom::find($p['product_uom_id'] ?? null) ?? throw new DomainException('That product unit is not known here.');
        $this->counts->record($actor, $c, Product::findOrFail($unit->product_id), $unit, (string) ($p['qty'] ?? '0'), $p['bin_id'] ?? null);

        return ['number' => $c->number];
    }

    private function countSubmit(Device $device, Actor $actor, array $p): array
    {
        $c = StockCount::find($p['count_id'] ?? null) ?? throw new DomainException('That count is not known here.');
        $this->warehouse($device, $actor, $c->warehouse_id, 'stock.count');
        if ($c->status === 'counting') {
            $c = $this->counts->submit($actor, $c);
        }

        return ['number' => $c->number, 'detail' => 'Submitted for approval.'];
    }
}
