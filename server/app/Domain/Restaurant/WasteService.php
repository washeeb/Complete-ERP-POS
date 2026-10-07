<?php

namespace App\Domain\Restaurant;

use App\Domain\Inventory\StockLedger;
use App\Domain\Modules\ModuleService;
use App\Domain\Pos\SupervisorApproval;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\RecipeLine;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\User;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Qty;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Kitchen waste log (owner decision 2026-10-18): kitchen staff record spoiled or dropped food at the till or the kitchen
 * screen; a dish counts as the ingredients of its recipe. Above a value limit a manager approves with their PIN.
 * Recorded as stock adjustments "Kitchen waste: …", so it shows in Stock → Shrink and the books (stock losses).
 */
final class WasteService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly StockLedger $ledger,
        private readonly ModuleService $modules,
        private readonly SupervisorApproval $approvals,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  list<array{product_id: string, product_uom_id: string, qty: string}>  $items
     * @return array{value_minor: int, currency: string, lines: int, approved_by: ?string}
     */
    public function record(Device $device, User $user, array $items, string $reason, ?array $approval = null): array
    {
        $branch = Branch::findOrFail($device->branch_id);
        if (! $this->modules->isEnabled($branch, 'restaurant')) {
            throw new DomainException('The Restaurant module is not on at this branch.', 'module_disabled');
        }
        if (! $this->authz->allows($user, 'restaurant.waste', $branch)) {
            throw new AccessDenied('You are not set up to record kitchen waste here.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 120) {
            throw new DomainException('Say what happened (e.g. burnt, dropped, past its date).');
        }
        $till = $device->till_id ? Till::find($device->till_id) : null;
        $warehouse = $till ? Warehouse::findOrFail($till->warehouse_id) : Warehouse::where('branch_id', $branch->id)->orderBy('code')->firstOrFail();

        $entries = [];
        foreach (array_values($items) as $it) {
            $product = Product::find($it['product_id'] ?? null) ?? throw new DomainException('Choose what was wasted.');
            $unit = ProductUom::where('product_id', $product->id)->find($it['product_uom_id'] ?? null) ?? throw new DomainException("{$product->name}: choose the unit.");
            $qty = Qty::toMilli((string) ($it['qty'] ?? '0'));
            if ($qty <= 0) {
                throw new DomainException("{$product->name}: enter how much was wasted.");
            }
            $recipe = RecipeLine::with(['ingredient', 'unit'])->where('product_id', $product->id)->get();
            if ($recipe->isNotEmpty()) {
                $dishes = Qty::toBaseMilli($qty, Qty::factorMicro($unit->factor));
                foreach ($recipe as $r) {
                    $entries[] = ['product' => $r->ingredient, 'unit' => $r->unit, 'qty_milli' => -Qty::divRound($dishes * Qty::toMilli((string) $r->qty), 1000)];
                }
            } elseif ($product->track_stock) {
                $entries[] = ['product' => $product, 'unit' => $unit, 'qty_milli' => -$qty];
            } else {
                throw new DomainException("{$product->name} holds no stock and has no recipe, so there is nothing to write off.");
            }
        }
        if ($entries === []) {
            throw new DomainException('Add what was wasted.');
        }

        // Value at average cost, to decide whether a manager must approve.
        $costs = DB::table('stock_levels')->where('warehouse_id', $warehouse->id)->whereIn('product_id', array_map(fn ($e) => $e['product']->id, $entries))->pluck('avg_cost_minor', 'product_id');
        $value = 0;
        foreach ($entries as $e) {
            $base = Qty::toBaseMilli(-$e['qty_milli'], Qty::factorMicro($e['unit']->factor));
            $value += (int) round($base * (float) ($costs[$e['product']->id] ?? 0) / 1000);
        }
        $limit = (int) $this->settings->get('restaurant.waste_approval_minor', $device->tenant_id);
        $approvedBy = null;
        if ($value > $limit && ! $this->authz->allows($user, 'restaurant.void', $branch)) {
            if (! $approval) {
                throw new DomainException(sprintf('Waste worth %.2f needs a manager’s approval.', $value / 100), 'waste_needs_approval');
            }
            $approvedBy = $this->approvals->verify($device, $approval, 'restaurant.void', $branch, null, 0, $user->id)->name;
        }

        $actor = Actor::user($user, 'pos', $device->id);
        $this->recorder->transaction(function () use ($actor, $warehouse, $entries, $reason, $approvedBy, $value, $branch) {
            $this->ledger->post($actor, $warehouse, 'adjustment', $entries, mb_substr("Kitchen waste: {$reason}".($approvedBy ? " (approved by {$approvedBy})" : ''), 0, 255),
                'kitchen_waste', (string) Str::uuid7());
            $this->recorder->audit->record($actor, 'kitchen_waste.recorded', $warehouse, ['value_minor' => [null, $value], 'lines' => [null, count($entries)]], $reason, $branch->id);
        });

        return ['value_minor' => $value, 'currency' => Tenant::find($device->tenant_id)->currency_code, 'lines' => count($entries), 'approved_by' => $approvedBy];
    }
}
