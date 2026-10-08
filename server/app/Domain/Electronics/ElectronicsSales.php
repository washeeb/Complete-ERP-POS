<?php

namespace App\Domain\Electronics;

use App\Domain\Inventory\SerialRegistry;
use App\Domain\Inventory\StockLedger;
use App\Domain\Pos\SupervisorApproval;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Sale;
use App\Models\TradeIn;
use App\Models\User;
use App\Models\Warehouse;
use App\Security\Authorizer;
use Illuminate\Support\Str;

/**
 * How a till sale meets Electronics (owner decision 2026-10-19): trade-ins handed in as part-payment (the used item goes
 * into stock at the value given, approved by a supervisor), and repair jobs paid for at collection.
 */
final class ElectronicsSales
{
    public function __construct(
        private readonly Authorizer $authz,
        private readonly SupervisorApproval $approvals,
        private readonly StockLedger $ledger,
        private readonly RepairService $repairs,
    ) {}

    /**
     * The trade-ins on a sale, checked: a stocked product, its serial number when it has one, a value, and someone who
     * may accept trade-ins (the cashier, or a supervisor's PIN).
     *
     * @return array{items: list<array{product: Product, unit: ProductUom, serial: ?string, condition: ?string, value: int}>, total: int, approved_by: ?string}
     */
    public function checkTradeIns(Device $device, User $user, array $p, Branch $branch, bool $offline, array &$flags): array
    {
        $items = [];
        foreach (array_values($p['trade_ins'] ?? []) as $t) {
            $product = Product::where('track_stock', true)->find($t['product_id'] ?? null) ?? throw new DomainException('Choose the item taken in (a product that holds stock).', 'trade_in_invalid');
            $unit = ProductUom::where('product_id', $product->id)->where('is_base', true)->firstOrFail();
            $value = (int) ($t['value_minor'] ?? 0);
            $serial = SerialRegistry::normalise([(string) ($t['serial'] ?? '')])[0] ?? null;
            if ($value <= 0 || ($product->track_serials && ! $serial)) {
                throw new DomainException("{$product->name}: enter the value given".($product->track_serials ? ' and its serial / IMEI number' : '').'.', 'trade_in_invalid');
            }
            $items[] = ['product' => $product, 'unit' => $unit, 'serial' => $serial, 'condition' => isset($t['condition']) ? mb_substr(trim((string) $t['condition']), 0, 255) ?: null : null,
                'value' => $value];
        }
        $total = array_sum(array_column($items, 'value'));
        $approvedBy = null;
        if ($items && ! $this->authz->allows($user, 'electronics.trade_in', $branch)) {
            if ($offline) {
                $flags[] = ['trade_in_unapproved', "{$user->name} took a trade-in worth ".number_format($total / 100, 2).' offline without a supervisor'];
            } elseif (empty($p['trade_in_approval'])) {
                throw new DomainException('A supervisor must approve the trade-in.', 'trade_in_needs_approval');
            } else {
                $approvedBy = $this->approvals->verify($device, $p['trade_in_approval'], 'electronics.trade_in', $branch, null, 0, $user->id)->name;
            }
        } elseif ($items) {
            $approvedBy = $user->name;
        }

        return ['items' => $items, 'total' => $total, 'approved_by' => $approvedBy];
    }

    /** After the sale is recorded (same transaction): trade-ins into stock, and repair jobs paid for marked collected. */
    public function settle(Actor $actor, Sale $sale, Warehouse $warehouse, array $tradeIns, array $p, bool $offline, array &$flags): void
    {
        if ($tradeIns['items']) {
            foreach ($tradeIns['items'] as $t) {
                TradeIn::create(['id' => (string) Str::uuid7(), 'tenant_id' => $sale->tenant_id, 'sale_id' => $sale->id, 'product_id' => $t['product']->id,
                    'serial' => $t['serial'], 'condition' => $t['condition'], 'value_minor' => $t['value'], 'approved_by_name' => $tradeIns['approved_by'], 'created_at' => now()]);
            }
            // Into stock at the value given (the till tender "trade_in" and this receipt meet in the trade-in clearing account).
            $this->ledger->post($actor, $warehouse, 'trade_in', array_map(fn ($t) => ['product' => $t['product'], 'unit' => $t['unit'], 'qty_milli' => 1000,
                'unit_cost_minor' => $t['value'], 'serials' => $t['serial'] ? [$t['serial']] : null], $tradeIns['items']),
                "Trade-in on {$sale->receipt_no}", 'sale', $sale->id, $sale->occurred_at, alreadyHappened: $offline);
            foreach ($this->ledger->serialAnomalies() as $problem) {
                $flags[] = ['serial_problem', "Trade-in offline: {$problem}"];
            }
        }
        foreach (array_unique(array_filter(array_column($p['lines'] ?? [], 'repair_job_id'))) as $jobId) {
            $this->repairs->paidBySale($actor, (string) $jobId, $sale, $offline, $flags);
        }
    }
}
