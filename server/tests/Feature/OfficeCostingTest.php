<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Purchasing\GoodsReceiptService;
use App\Domain\Purchasing\PurchaseOrderService;
use App\Domain\Purchasing\SupplierService;
use App\Models\LandedCost;
use App\Models\PurchaseOrder;
use App\Models\StockCostRun;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

// Landed costs, Cost runs, Estimated costs and Spend analytics in the new back office (part 19).

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->b['HQ']->id)->first();
    withMfa($this->owner);
});

it('lists landed costs and spreads freight over a delivery', function () {
    $milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00']);
    $ctn = $milo->units->firstWhere('is_base', false);
    $supplier = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => 'Kofi Wholesale']);
    $buyer = staff($this->owner, 'ama', 2, 'purchasing_officer');
    $manager = staff($this->owner, 'kojo', 4, 'warehouse_manager', ScopeType::Branch, $this->b['HQ']->id);
    $keeper = staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->b['HQ']->id);
    $po = app(PurchaseOrderService::class)->saveDraft(actorFor($buyer), new PurchaseOrder, ['supplier_id' => $supplier->id, 'warehouse_id' => $this->hq->id,
        'lines' => [['product_uom_id' => $ctn->id, 'qty' => '10', 'unit_cost' => '240.00']]]);
    app(PurchaseOrderService::class)->submit(actorFor($buyer), $po);
    $po = app(PurchaseOrderService::class)->approve(actorFor($manager), $po->fresh());
    $grn = app(GoodsReceiptService::class)->receive(actorFor($keeper), $po, ['lines' => [['purchase_order_line_id' => $po->lines[0]->id, 'qty' => '10']]]);

    $accountant = withMfa(staff($this->owner, 'esi', 4, 'accountant'));
    $this->actingAs($accountant, 'web');
    expect($this->getJson('/office-api/landed-costs')->assertOk()->json())->toMatchArray(['total' => 0, 'can_spread' => true]);
    expect(collect($this->getJson('/office-api/landed-costs/form')->assertOk()->json('receipts'))->pluck('id'))->toContain($grn->id);
    $this->postJson('/office-api/landed-costs', ['receipts' => [$grn->id], 'method' => 'value', 'charges' => []])->assertStatus(422);
    $id = $this->postJson('/office-api/landed-costs', ['receipts' => [$grn->id], 'method' => 'value', 'note' => 'Waybill 4471',
        'charges' => [['kind' => 'freight', 'description' => 'Freight Tema–Tarkwa', 'amount' => '480.00']]])->assertOk()->json('id');
    expect(LandedCost::find($id))->total_minor->toBe(48000)->to_stock_minor->toBe(48000);
    $list = $this->getJson('/office-api/landed-costs?q=tema')->assertOk()->json();
    expect($list['total'])->toBe(1)->and($list['rows'][0])->toMatchArray(['total' => 48000, 'method' => 'By value', 'charges' => 'Freight Tema–Tarkwa']);
    $lines = $this->getJson("/office-api/landed-costs/{$id}")->assertOk()->json();
    expect($lines['lines'][0])->toMatchArray(['product' => 'MILO 400G', 'allocated' => 48000, 'before' => '10.0000', 'after' => '12.0000']);

    // Someone who may only look sees the list, not the spread form.
    $this->actingAs(withMfa($buyer), 'web');
    $this->getJson('/office-api/landed-costs')->assertOk();
    $this->getJson('/office-api/landed-costs/form')->assertForbidden();
});

it('prepares a cost run, previews it, posts it and undoes it; estimated costs show it', function () {
    app(Settings::class)->set(actorFor($this->owner), 'stock.cost_run_two_people', false);
    $p = makeProduct($this->owner, 'NOCOST', 'Amoxicillin', retail: '14.00')->fresh(['units']);
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->hq, $p, $p->units->firstWhere('is_base', true), '10', 'opening', '', 'Loaded');

    $this->actingAs($this->owner, 'web');
    session()->put('stock_cost_run.products', [$p->id]); // ticked on the products list
    $form = $this->getJson('/office-api/cost-runs/form')->assertOk()->json();
    expect($form['values'])->toMatchArray(['method' => 'divide', 'value' => '1.4', 'tax_mode' => 'shelf'])
        ->and($form['values']['products'][0]['id'])->toBe($p->id)->and($form['price_lists'])->not->toBeEmpty();
    expect(collect($this->getJson('/office-api/cost-runs/products?q=amox')->json('results'))->pluck('id'))->toContain($p->id);
    $this->postJson('/office-api/cost-runs', ['method' => 'divide', 'value' => '0.5', 'price_list_id' => $form['values']['price_list_id'], 'tax_mode' => 'shelf',
        'effective_on' => now()->toDateString()])->assertStatus(422); // a factor must be above 1
    $id = $this->postJson('/office-api/cost-runs', ['method' => 'divide', 'value' => '1.4', 'price_list_id' => $form['values']['price_list_id'], 'tax_mode' => 'shelf',
        'product_ids' => [$p->id], 'catch_up' => true, 'overwrite' => false, 'effective_on' => now()->toDateString()])->assertOk()->json('id');

    $run = $this->getJson("/office-api/cost-runs/{$id}")->assertOk()->json();
    expect($run)->toMatchArray(['status' => 'draft', 'stock_value' => 10000, 'lines_count' => 1, 'done' => false])
        ->and($run['products']['rows'][0])->toMatchArray(['product' => 'AMOXICILLIN', 'kind' => 'estimate', 'new_cost' => 1000])
        ->and($run['actions'])->toMatchArray(['recalculate' => true, 'submit' => false, 'post' => true, 'cancel' => true, 'undo' => false]);
    expect($this->getJson("/office-api/cost-runs/{$id}?kind=skipped")->json('products.total'))->toBe(0);
    expect($this->getJson('/office-api/cost-runs?status=draft')->json('total'))->toBe(1);

    $this->postJson("/office-api/cost-runs/{$id}/action", ['action' => 'recalculate'])->assertOk();
    $posted = $this->postJson("/office-api/cost-runs/{$id}/action", ['action' => 'post'])->assertOk()->json();
    expect($posted['run'])->toMatchArray(['status' => 'posted', 'done' => true])->and($posted['run']['actions']['undo'])->toBeTrue();
    expect((float) DB::table('stock_levels')->where('warehouse_id', $this->hq->id)->where('product_id', $p->id)->value('avg_cost_minor'))->toBe(1000.0);

    $est = $this->getJson('/office-api/estimated-costs')->assertOk()->json();
    expect($est['total'])->toBe(1)->and($est['rows'][0])->toMatchArray(['product' => 'AMOXICILLIN', 'estimate' => 1000, 'real' => null])
        ->and($est['summary'])->toContain('1 product(s)');
    expect($this->getJson('/office-api/estimated-costs?delivered=yes')->json('total'))->toBe(0);

    $this->postJson("/office-api/cost-runs/{$id}/action", ['action' => 'undo'])->assertStatus(422); // a reason is needed
    $undo = $this->postJson("/office-api/cost-runs/{$id}/action", ['action' => 'undo', 'reason' => 'Wrong factor'])->assertOk()->json();
    $back = StockCostRun::where('reverses_id', $id)->sole();
    expect($undo['run'])->toMatchArray(['status' => 'reversed', 'reversed_by' => ['number' => $back->number, 'open' => "/office/cost-runs/{$back->id}"]])
        ->and($this->getJson("/office-api/cost-runs/{$back->id}")->json('reverses.open'))->toBe("/office/cost-runs/{$id}");
});

it('shows spend analytics with its export, and keeps the costing screens to those allowed', function () {
    $this->actingAs($this->owner, 'web');
    $d = $this->getJson('/office-api/spend-analytics?from=2026-01-01&to=2026-12-31&branch=nonsense')->assertOk()->json();
    expect($d)->toMatchArray(['from' => '2026-01-01', 'to' => '2026-12-31', 'branch' => ''])->and($d['months'])->toHaveCount(12)
        ->and($d['totals'])->toMatchArray(['spend' => 0, 'bills' => 0]);
    $this->get('/office-api/spend-analytics/export?format=csv')->assertOk();

    $cashier = withMfa(staff($this->owner, 'efua', 3, 'cashier', ScopeType::Branch, $this->b['HQ']->id));
    $this->actingAs($cashier, 'web');
    foreach (['landed-costs', 'cost-runs', 'cost-runs/form', 'estimated-costs', 'spend-analytics'] as $path) {
        $this->getJson("/office-api/{$path}")->assertForbidden();
    }
});
