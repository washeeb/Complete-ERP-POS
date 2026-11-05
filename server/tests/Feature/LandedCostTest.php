<?php

use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\GlCheck;
use App\Domain\Inventory\InventoryService;
use App\Domain\Purchasing\GoodsReceiptService;
use App\Domain\Purchasing\LandedCostService;
use App\Domain\Purchasing\PurchaseOrderService;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\DomainException;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00']);
    $this->ctn = $this->milo->units->firstWhere('is_base', false);
    $this->supplier = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => 'Nestlé Ghana']);
    $this->accountant = staff($this->owner, 'esi', 4, 'accountant');
    $buyer = staff($this->owner, 'ama', 2, 'purchasing_officer');
    $manager = staff($this->owner, 'kojo', 4, 'warehouse_manager', ScopeType::Branch, $this->branches['HQ']->id);
    $keeper = staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->branches['HQ']->id);
    $po = app(PurchaseOrderService::class)->saveDraft(actorFor($buyer), new PurchaseOrder, ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->hq->id,
        'lines' => [['product_uom_id' => $this->ctn->id, 'qty' => '10', 'unit_cost' => '240.00']]]);
    app(PurchaseOrderService::class)->submit(actorFor($buyer), $po);
    $po = app(PurchaseOrderService::class)->approve(actorFor($manager), $po->fresh());
    // 240 pieces at GHS 10.00 each.
    $this->grn = app(GoodsReceiptService::class)->receive(actorFor($keeper), $po, ['lines' => [['purchase_order_line_id' => $po->lines[0]->id, 'qty' => '10']]]);
});

function miloAvg($t): string
{
    return (string) DB::table('stock_levels')->where('warehouse_id', $t->hq->id)->where('product_id', $t->milo->id)->value('avg_cost_minor');
}

it('spreads freight over a delivery: the share for stock still here raises its cost, the share for what was sold goes to cost of sales', function () {
    expect(miloAvg($this))->toBe('1000.0000');
    // 40 of the 240 pieces have gone before the freight bill arrives.
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->hq, $this->milo, $this->milo->units->firstWhere('is_base', true), '-40', 'adjustment', 'damaged');

    $lc = app(LandedCostService::class)->post(actorFor($this->accountant), [$this->grn->id],
        [['kind' => 'freight', 'description' => 'Freight Tema–Tarkwa', 'amount_minor' => 48000]]);
    expect($lc)->number->toMatch('/^LC-/')->total_minor->toBe(48000)->to_stock_minor->toBe(40000)->to_cogs_minor->toBe(8000)
        ->and(miloAvg($this))->toBe('1200.0000'); // GHS 400 over the 200 pieces left: GHS 2.00 more each

    app(AutoPoster::class)->run($this->tenant->id);
    $gl = fn (string $key) => (int) DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
        ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')->where('accounts.system_key', $key)->where('journal_entries.memo', 'like', 'Landed cost%')
        ->sum('journal_lines.amount_minor');
    expect($gl('inventory'))->toBe(40000)->and($gl('cogs'))->toBe(8000)->and($gl('landed_clearing'))->toBe(-48000);
});

it('keeps stock at cost tied to the ledger through a landed cost, a correction and selling everything at the raised cost', function () {
    $svc = app(LandedCostService::class);
    $inventory = fn () => collect(app(GlCheck::class)->run($this->tenant->id)['ties'])->firstWhere('control', 'inventory');
    $tied = function (int $value) use ($inventory) {
        app(AutoPoster::class)->run($this->tenant->id);
        expect($inventory())->ledger->toBe($value)->sub_ledger->toBe($value)->difference->toBe(0)
            ->and(app(GlCheck::class)->run($this->tenant->id)['problems'])->toBe([]);
    };
    $piece = $this->milo->units->firstWhere('is_base', true);
    $out = fn (string $qty) => app(InventoryService::class)->adjust(actorFor($this->owner), $this->hq, $this->milo, $piece, $qty, 'adjustment', 'damaged');

    $tied(240000); // 240 pieces at GHS 10.00
    $out('-40');
    $tied(200000);

    // GHS 480 of freight: GHS 400 for the 200 pieces left, GHS 80 to cost of sales. No stock movement carries the GHS 400.
    $lc = $svc->post(actorFor($this->accountant), [$this->grn->id], [['kind' => 'freight', 'description' => 'Freight', 'amount_minor' => 48000]]);
    expect($lc->to_stock_minor)->toBe(40000);
    $tied(240000);

    // Half goes out at the raised cost of GHS 12.00, more than the GHS 10.00 they came in at.
    $out('-100');
    $tied(120000);

    // A GHS 144 correction: GHS 60 off the 100 pieces left (GHS 0.60 each), GHS 84 off cost of sales for the 140 gone.
    $fix = $svc->post(actorFor($this->accountant), [$this->grn->id], [['description' => 'Freight overstated', 'amount_minor' => -14400]]);
    expect($fix->to_stock_minor)->toBe(-6000)->and(miloAvg($this))->toBe('1140.0000');
    $tied(114000);

    // Everything gone: nothing left in stock, on either side.
    $out('-100');
    $tied(0);
    expect((int) DB::table('stock_movements')->where('tenant_id', $this->tenant->id)->sum(DB::raw('ROUND(qty_entered * COALESCE(unit_cost_minor, 0))')))->toBe(-34000); // what the freight added, on its own
});

it('splits to the pesewa, corrects with a negative charge, and refuses what does not fit', function () {
    expect(LandedCostService::spread(100, ['a' => 1, 'b' => 1, 'c' => 1]))->toBe(['a' => 34, 'b' => 33, 'c' => 33])
        ->and(LandedCostService::spread(-100, ['a' => 1, 'b' => 1, 'c' => 1]))->toBe(['a' => -34, 'b' => -33, 'c' => -33]);

    $svc = app(LandedCostService::class);
    $svc->post(actorFor($this->accountant), [$this->grn->id], [['description' => 'Duty', 'amount_minor' => 24000]], 'quantity');
    expect(miloAvg($this))->toBe('1100.0000');
    $svc->post(actorFor($this->accountant), [$this->grn->id], [['description' => 'Duty overstated', 'amount_minor' => -12000]]);
    expect(miloAvg($this))->toBe('1050.0000');

    expect(fn () => $svc->post(actorFor($this->accountant), [], [['description' => 'x', 'amount_minor' => 1]]))->toThrow(DomainException::class, 'Choose the deliveries')
        ->and(fn () => $svc->post(actorFor($this->accountant), [$this->grn->id], [['description' => 'x', 'amount_minor' => 0]]))->toThrow(DomainException::class, 'at least one charge')
        ->and(fn () => $svc->post(actorFor(staff($this->owner, 'yaw2', 2, 'storekeeper')), [$this->grn->id], [['description' => 'x', 'amount_minor' => 100]]))->toThrow(Exception::class);
});
