<?php

use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\GlCheck;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockCostRuns;
use App\Domain\Inventory\StockLedger;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

// Owner request 2026-11-15 (SUPBOG): goods sold below zero went out at no cost (no average yet). When stock with a real
// cost comes in, they are costed at that cost: cost of sales rises, stock value in the books and the ledger drop together.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->wh = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->a = actorFor($this->owner);
    $this->rice = makeProduct($this->owner, 'RICE-5', 'Rice 5 kg', retail: '120.00');
    $this->sell = fn (int $qty, $product = null) => DB::transaction(fn () => app(StockLedger::class)->post($this->a, $this->wh, 'sale',
        [['product' => $product ?? $this->rice, 'unit' => ($product ?? $this->rice)->units->first(), 'qty_milli' => -$qty * 1000]], 'Sale', 'sale', null, alreadyHappened: true));
    $this->level = fn ($product = null) => StockLevel::where('warehouse_id', $this->wh->id)->where('product_id', ($product ?? $this->rice)->id)->first();
    $this->ties = function () {
        app(AutoPoster::class)->run($this->tenant->id);

        return collect(app(GlCheck::class)->run($this->tenant->id)['ties'])->keyBy('control');
    };
    $this->cogs = fn () => (int) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.system_key', 'cogs')->sum('l.amount_minor');
});

it('costs goods sold below zero when the opening stock comes in, at its cost', function () {
    ($this->sell)(3); // sold before the count was loaded: on hand -3, at no cost
    app(InventoryService::class)->adjust($this->a, $this->wh, $this->rice, $this->rice->units->first(), '10', 'opening', '', null, '95.00');

    $settle = StockMovement::where('type', StockLedger::OVERSOLD)->orderBy('qty_base')->get();
    expect((string) ($this->level)()->on_hand)->toBe('7.000') // the quantity does not change
        ->and((int) round((float) ($this->level)()->avg_cost_minor))->toBe(9500) // nor the average
        ->and($settle)->toHaveCount(2)->and((string) $settle[0]->qty_base)->toBe('-3.000')->and($settle[0]->unit_cost_minor)->toBe(9500)
        ->and($settle[1]->unit_cost_minor)->toBeNull()
        ->and(app(StockLedger::class)->check($this->tenant->id))->toBe([]);

    $ties = ($this->ties)();
    expect($ties['inventory']['sub_ledger'])->toBe(7 * 9500)->and($ties['inventory']['difference'])->toBe(0) // the 7 left, not the 10 counted
        ->and(($this->cogs)())->toBe(3 * 9500); // the 3 sold now cost what they did
});

it('settles only what came in, the rest when more arrives, each at its own cost', function () {
    ($this->sell)(5);
    app(InventoryService::class)->adjust($this->a, $this->wh, $this->rice, $this->rice->units->first(), '2', 'opening', '', null, '90.00');
    expect(StockLedger::unsettledAtNoCost($this->wh->id, $this->rice->id))->toBe(3000)->and((string) ($this->level)()->on_hand)->toBe('-3.000');

    app(InventoryService::class)->adjust($this->a, $this->wh, $this->rice, $this->rice->units->first(), '10', 'adjustment', 'received_costed', null, '100.00');
    expect(StockLedger::unsettledAtNoCost($this->wh->id, $this->rice->id))->toBe(0)->and((string) ($this->level)()->on_hand)->toBe('7.000')
        ->and(($this->ties)()['inventory']['difference'])->toBe(0)
        ->and(($this->cogs)())->toBe(2 * 9000 + 3 * 10000);
});

it('leaves alone sales that already had a cost, and a cost run does not catch settled sales up again', function () {
    $oil = makeProduct($this->owner, 'OIL-1', 'Oil 1 L', retail: '45.00');
    app(InventoryService::class)->adjust($this->a, $this->wh, $oil, $oil->units->first(), '2', 'opening', '', null, '30.00');
    ($this->sell)(5, $oil); // costed at the average (30.00), going 3 below zero
    app(InventoryService::class)->adjust($this->a, $this->wh, $oil, $oil->units->first(), '10', 'adjustment', 'received_costed', null, '32.00');
    expect(StockMovement::where('type', StockLedger::OVERSOLD)->where('product_id', $oil->id)->count())->toBe(0);

    ($this->sell)(4);
    app(InventoryService::class)->adjust($this->a, $this->wh, $this->rice, $this->rice->units->first(), '10', 'opening', '', null, '95.00');
    $sold = (new ReflectionMethod(StockCostRuns::class, 'soldAtNoCost'))->invoke(app(StockCostRuns::class), $this->tenant->id);
    expect($sold)->not->toHaveKey("{$this->wh->id}|{$this->rice->id}");
});
