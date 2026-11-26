<?php

use App\Domain\Customers\CustomerService;
use App\Domain\Inventory\InventoryService;
use App\Models\Customer;
use App\Models\RepTarget;
use App\Models\SalesRoute;
use App\Models\StockLevel;
use App\Models\Till;
use App\Models\VanSettlement;
use App\Models\Warehouse;
use App\Security\ScopeType;

// Sales & distribution in the new back office (part 34): vans, van settlements, routes, visits, rep targets, rep performance.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->hq = $this->b['HQ'];
    enableModule($this->owner, $this->hq, 'distribution');
    withMfa($this->owner);
    $this->depot = Warehouse::where('branch_id', $this->hq->id)->first();
    $this->rep = staff($this->owner, 'kojo', 2, 'van_sales_rep', ScopeType::Branch, $this->hq->id);
    $this->malt = makeProduct($this->owner, 'MALT-33', 'Malt 330 ml', retail: '10.00');
    $this->unit = $this->malt->units->first();
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->depot, $this->malt, $this->unit, '200', 'opening', '', null, '7.00');
});

it('adds a van with its till, loads and unloads it, and settles the day', function () {
    $this->actingAs($this->owner, 'web');
    $form = $this->getJson('/office-api/distribution/vans')->assertOk()->json('form');
    expect(collect($form['warehouses'])->pluck('id'))->toContain($this->depot->id);
    $id = $this->postJson('/office-api/distribution/vans', ['branch_id' => $this->hq->id, 'name' => 'Kumasi van 1', 'vehicle_reg' => 'gr 1234-24', 'rep_user_id' => $this->rep->id,
        'home_warehouse_id' => $this->depot->id])->assertOk()->json('id');
    $van = Warehouse::find($id);
    expect($van->only(['is_van', 'vehicle_reg']))->toBe(['is_van' => true, 'vehicle_reg' => 'GR 1234-24'])->and(Till::where('warehouse_id', $id)->exists())->toBeTrue();

    $this->postJson("/office-api/distribution/vans/{$id}/action", ['action' => 'load', 'lines' => [['product_uom_id' => $this->unit->id, 'qty' => '40']]])->assertOk();
    $this->postJson("/office-api/distribution/vans/{$id}/action", ['action' => 'unload', 'lines' => [['product_uom_id' => $this->unit->id, 'qty' => '5']]])->assertOk();
    expect((string) StockLevel::where('warehouse_id', $id)->where('product_id', $this->malt->id)->value('on_hand'))->toBe('35.000');
    $row = collect($this->getJson('/office-api/distribution/vans')->json('rows'))->firstWhere('id', $id);
    expect($row)->toMatchArray(['name' => 'Kumasi van 1', 'rep' => 'Kojo', 'stock' => '35 units']);
    $this->postJson("/office-api/distribution/vans/{$id}", ['name' => 'Kumasi van A', 'home_warehouse_id' => $this->depot->id])->assertOk();
    expect($van->fresh()->name)->toBe('Kumasi van A');

    $open = $this->postJson("/office-api/distribution/vans/{$id}/action", ['action' => 'settle'])->assertOk()->json('open');
    $s = VanSettlement::where('van_warehouse_id', $id)->sole();
    expect($open)->toBe("/office/distribution/settlements/{$s->id}");
    $this->postJson("/office-api/distribution/vans/{$id}/action", ['action' => 'settle'])->assertStatus(422); // already open
    $one = $this->getJson("/office-api/distribution/settlements/{$s->id}")->assertOk()->json();
    expect($one['lines'][0])->toMatchArray(['sku' => 'MALT-33', 'expected' => '35.000'])->and($one['actions'])->toMatchArray(['count' => true, 'submit' => true, 'post' => false]);
    // The storekeeper counts and submits; someone else approves (whoever submits cannot settle their own).
    $keeper = staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->hq->id);
    $this->actingAs($keeper, 'web');
    $this->postJson("/office-api/distribution/settlements/{$s->id}/action", ['action' => 'submit'])->assertStatus(422); // not counted
    $this->postJson("/office-api/distribution/settlements/{$s->id}/action", ['action' => 'count', 'counts' => [$this->malt->id => ['counted' => '34', 'unload' => '30']]])->assertOk();
    $this->postJson("/office-api/distribution/settlements/{$s->id}/action", ['action' => 'submit', 'note' => 'One bottle broke'])->assertOk();
    $this->postJson("/office-api/distribution/settlements/{$s->id}/action", ['action' => 'post'])->assertStatus(403); // not theirs to settle
    $this->actingAs($this->owner, 'web');
    $this->postJson("/office-api/distribution/settlements/{$s->id}/action", ['action' => 'post'])->assertOk();
    expect($s->fresh()->status)->toBe('posted')
        ->and((string) StockLevel::where('warehouse_id', $id)->where('product_id', $this->malt->id)->value('on_hand'))->toBe('4.000');
    $list = $this->getJson('/office-api/distribution/settlements')->assertOk()->json();
    expect($list['rows'][0])->toMatchArray(['number' => $s->number, 'status' => 'posted']);
    $this->get("/office-api/distribution/settlements/{$s->id}/sheet")->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('keeps routes with their customers in order, and archives one', function () {
    $o = actorFor($this->owner);
    $ama = app(CustomerService::class)->save($o, new Customer, ['name' => 'Ama Provisions', 'phone' => '0241112222']);
    $kofi = app(CustomerService::class)->save($o, new Customer, ['name' => 'Kofi Stores', 'phone' => '0241113333']);
    $this->actingAs($this->owner, 'web');
    expect(collect($this->getJson('/office-api/distribution/customers?q=stores')->assertOk()->json('results'))->pluck('id')->all())->toBe([$kofi->id]);
    $this->postJson('/office-api/distribution/routes', ['name' => '', 'branch_id' => $this->hq->id])->assertStatus(422);
    $id = $this->postJson('/office-api/distribution/routes', ['name' => 'Adum Monday', 'branch_id' => $this->hq->id, 'rep_user_id' => $this->rep->id, 'days' => [1, 4],
        'customer_ids' => [$kofi->id, $ama->id]])->assertOk()->json('id');
    $row = collect($this->getJson('/office-api/distribution/routes')->assertOk()->json('rows'))->firstWhere('id', $id);
    expect($row)->toMatchArray(['name' => 'Adum Monday', 'days' => 'Mon Thu', 'rep' => 'Kojo', 'customers' => 2])
        ->and(collect($row['values']['stops'])->pluck('id')->all())->toBe([$kofi->id, $ama->id]);
    $this->postJson("/office-api/distribution/routes/{$id}", ['name' => 'Adum Monday', 'branch_id' => $this->hq->id, 'days' => [1], 'customer_ids' => [$ama->id, $kofi->id]])->assertOk();
    expect(SalesRoute::find($id)->stops()->pluck('customer_id')->all())->toBe([$ama->id, $kofi->id])->and(SalesRoute::find($id)->days)->toBe([1]);
    $this->postJson('/office-api/distribution/archive', ['kind' => 'route', 'id' => $id, 'reason' => 'Merged with Adum Thursday'])->assertOk();
    expect($this->getJson('/office-api/distribution/routes')->json('rows'))->toBe([]);
});

it('sets rep targets, lists visits and shows rep performance', function () {
    $this->actingAs($this->owner, 'web');
    $t = $this->getJson('/office-api/distribution/targets')->assertOk()->json();
    expect(collect($t['reps'])->pluck('id'))->toContain($this->rep->id);
    $this->postJson('/office-api/distribution/targets', ['user_id' => $this->rep->id, 'year' => now()->year, 'month' => 13, 'target' => '5000'])->assertStatus(422);
    $this->postJson('/office-api/distribution/targets', ['user_id' => $this->rep->id, 'year' => now()->year, 'month' => now()->month, 'target' => '5000', 'visit_target' => 60])->assertOk();
    expect(RepTarget::sole()->only(['target_minor', 'visit_target']))->toBe(['target_minor' => 500000, 'visit_target' => 60]);
    expect($this->getJson('/office-api/distribution/targets')->json('rows.0'))->toMatchArray(['rep' => 'Kojo', 'target' => 500000, 'visits' => 60]);

    $v = $this->getJson('/office-api/distribution/visits?today=1')->assertOk()->json();
    expect($v['total'])->toBe(0)->and(collect($v['outcomes'])->pluck('id'))->toContain('sold');
    $p = $this->getJson('/office-api/distribution/performance')->assertOk()->json();
    expect($p['from'])->toBe(now()->startOfMonth()->toDateString())->and(collect($p['reps'])->firstWhere('name', 'Kojo'))->toMatchArray(['target_minor' => 500000]);

    $cashier = staff($this->owner, 'yaa', 3, 'cashier', ScopeType::Branch, $this->hq->id);
    $this->actingAs($cashier, 'web');
    $this->getJson('/office-api/distribution/targets')->assertForbidden();
});
