<?php

use App\Domain\Customers\CustomerService;
use App\Domain\Inventory\InventoryService;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MaintenanceAsset;
use App\Models\MaintenanceOrder;
use App\Models\MaintenancePlan;
use App\Models\Warehouse;
use App\Security\ScopeType;

// Maintenance in the new back office (part 31): equipment (with servicing plans) and work orders.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    activateTestLicense(['features' => ['finance', 'hr', 'service']]);
    $this->hq = $this->b['HQ'];
    withMfa($this->owner);
    $this->store = Warehouse::where('branch_id', $this->hq->id)->first();
    $this->tech = staff($this->owner, 'kofi', 2, 'technician', ScopeType::Branch, $this->hq->id);
    $this->customer = app(CustomerService::class)->save(actorFor($this->owner), new Customer, ['name' => 'Ama Darko', 'phone' => '0244000111']);
    $this->part = makeProduct($this->owner, 'FLT', 'Oil filter', retail: '80.00', tax: 'EXEMPT');
    $this->unit = $this->part->units->firstWhere('is_base', true);
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->store, $this->part, $this->unit, '10', 'opening', '', unitCost: '50.00');
});

it('keeps equipment with its plans and meter, reports a breakdown, and archives only when nothing is open', function () {
    $this->actingAs($this->owner, 'web');
    $form = $this->getJson('/office-api/maintenance/equipment/form')->assertOk()->json();
    expect($form['actions']['edit'])->toBeTrue()->and(collect($form['categories'])->pluck('id'))->toContain('generator');
    $this->postJson('/office-api/maintenance/equipment', ['name' => 'Generator', 'owner' => 'own', 'category' => 'generator'])->assertStatus(422); // no branch
    $id = $this->postJson('/office-api/maintenance/equipment', ['name' => 'Generator 20 kVA', 'owner' => 'own', 'category' => 'generator', 'branch_id' => $this->hq->id,
        'location' => 'Back yard', 'meter_unit' => 'hours'])->assertOk()->json('id');
    $list = $this->getJson('/office-api/maintenance/equipment')->assertOk()->json();
    expect($list['total'])->toBe(1)->and($list['rows'][0])->toMatchArray(['name' => 'Generator 20 kVA', 'category' => 'Generator', 'status' => 'working']);

    $this->postJson("/office-api/maintenance/equipment/{$id}/plans", ['name' => 'Oil change'])->assertStatus(422); // how often?
    $this->postJson("/office-api/maintenance/equipment/{$id}/plans", ['name' => 'Oil change', 'every_days' => 90, 'every_meter' => 250, 'lead_days' => 7, 'is_active' => true,
        'checklist' => ['Drain oil', 'Change filter', '']])->assertOk();
    expect(MaintenancePlan::where('asset_id', $id)->sole()->checklist)->toBe(['Drain oil', 'Change filter']);
    $this->postJson("/office-api/maintenance/equipment/{$id}/action", ['action' => 'meter', 'reading' => 1000])->assertOk();
    $one = $this->getJson("/office-api/maintenance/equipment/{$id}")->assertOk()->json();
    expect($one['meter'])->toBe('1000 h')->and($one['plans'][0])->toMatchArray(['name' => 'Oil change', 'active' => true])
        ->and($one['actions'])->toMatchArray(['breakdown' => true, 'meter' => true, 'plans' => true]);

    $open = $this->postJson("/office-api/maintenance/equipment/{$id}/action", ['action' => 'breakdown', 'title' => 'Will not start'])->assertOk()->json('open');
    $o = MaintenanceOrder::where('asset_id', $id)->sole();
    expect($open)->toBe("/office/maintenance/orders/{$o->id}")->and($o->only(['kind', 'priority']))->toBe(['kind' => 'breakdown', 'priority' => 'high'])
        ->and(MaintenanceAsset::find($id)->status)->toBe('down');
    expect($this->getJson("/office-api/maintenance/equipment/{$id}")->json('archive.archive_blockers'))->not->toBe([]);
    $this->postJson("/office-api/maintenance/equipment/{$id}/archive", ['reason' => 'Sold'])->assertStatus(422); // a work order is open
    $this->postJson("/office-api/maintenance/orders/{$o->id}/action", ['action' => 'cancel', 'why' => 'Reported twice'])->assertOk();
    $this->postJson("/office-api/maintenance/equipment/{$id}/archive", ['reason' => 'Sold'])->assertOk();
    expect($this->getJson('/office-api/maintenance/equipment')->json('total'))->toBe(0)->and($this->getJson('/office-api/maintenance/equipment?show=archived')->json('total'))->toBe(1);
    expect($this->getJson("/office-api/maintenance/equipment/{$id}")->json('archive'))->toMatchArray(['archived' => true, 'can_restore' => true, 'can_archive' => false]);
    $this->postJson("/office-api/maintenance/equipment/{$id}/archive", ['restore' => true])->assertOk();
    expect(MaintenanceAsset::find($id)->archived_at)->toBeNull();
});

it('works a work order: start, time, parts in and back, waiting, done with its checklist, and invoices a customer', function () {
    $this->actingAs($this->owner, 'web');
    $mower = $this->postJson('/office-api/maintenance/equipment', ['name' => 'Customer mower', 'owner' => 'customer', 'category' => 'other', 'customer_id' => $this->customer->id,
        'branch_id' => $this->hq->id, 'warranty_until' => now()->subMonth()->toDateString()])->assertOk()->json('id');
    $form = $this->getJson("/office-api/maintenance/orders/form?asset={$mower}")->assertOk()->json();
    expect($form['values']['asset_id'])->toBe($mower)->and(collect($form['people'])->pluck('id'))->toContain($this->tech->id);
    $id = $this->postJson('/office-api/maintenance/orders', ['asset_id' => $mower, 'kind' => 'visit', 'priority' => 'normal', 'title' => 'Blade worn',
        'assigned_to' => $this->tech->id])->assertOk()->json('id');
    $list = $this->getJson('/office-api/maintenance/orders')->assertOk()->json();
    expect($list['total'])->toBe(1)->and($list['rows'][0])->toMatchArray(['title' => 'Blade worn', 'asset' => 'Customer mower', 'who' => 'Kofi', 'status_label' => 'To do']);

    $act = fn (array $b) => $this->postJson("/office-api/maintenance/orders/{$id}/action", $b);
    $act(['action' => 'start'])->assertOk();
    $act(['action' => 'labour', 'hours' => '1.5', 'note' => 'Sharpening'])->assertOk();
    expect(collect($this->getJson('/office-api/maintenance/units?q=filter')->assertOk()->json('results'))->pluck('id'))->toContain($this->unit->id);
    $act(['action' => 'parts', 'warehouse_id' => $this->store->id, 'lines' => [['product_uom_id' => $this->unit->id, 'qty' => '2']]])->assertOk();
    $act(['action' => 'parts_back', 'warehouse_id' => $this->store->id, 'lines' => [['product_uom_id' => $this->unit->id, 'qty' => '1']]])->assertOk();
    $act(['action' => 'wait', 'why' => 'Blade on order'])->assertOk();
    $one = $this->getJson("/office-api/maintenance/orders/{$id}")->assertOk()->json();
    expect($one)->toMatchArray(['status' => 'waiting', 'labour' => '1 h 30 min', 'parts_minor' => 5000, 'paid_by' => 'The customer'])
        ->and($one['actions'])->toMatchArray(['wait' => false, 'complete' => true, 'invoice' => false]);

    $act(['action' => 'invoice'])->assertStatus(422); // not done yet... and only for "done"
    $act(['action' => 'complete'])->assertStatus(422);
    $act(['action' => 'complete', 'outcome' => 'Blade replaced'])->assertOk();
    expect(MaintenanceOrder::find($id)->status)->toBe('done')->and($this->getJson("/office-api/maintenance/orders/{$id}")->json('actions.invoice'))->toBeTrue();
    $service = makeProduct($this->owner, 'LAB', 'Repair labour', retail: '0.00', tax: 'EXEMPT');
    $service->forceFill(['track_stock' => false])->save();
    expect(collect($this->getJson('/office-api/maintenance/services?q=labour')->json('results'))->pluck('id'))->toContain($service->id);
    $open = $act(['action' => 'invoice', 'labour' => '50', 'service' => $service->id])->assertOk()->json('open');
    $invoice = Invoice::find(MaintenanceOrder::find($id)->invoice_id);
    expect($open)->toBe("/office/invoices/{$invoice->id}")->and($invoice->net_minor)->toBe(8000 + 5000);
    expect($this->getJson('/office-api/maintenance/orders?open=0')->json('total'))->toBe(1);
});

it('lets technicians work orders but not change equipment, and asks for the checklist', function () {
    $this->actingAs($this->owner, 'web');
    $gen = $this->postJson('/office-api/maintenance/equipment', ['name' => 'Generator', 'owner' => 'own', 'category' => 'generator', 'branch_id' => $this->hq->id])->assertOk()->json('id');
    $this->postJson("/office-api/maintenance/equipment/{$gen}/plans", ['name' => 'Service', 'every_days' => 30, 'is_active' => true, 'checklist' => ['Check oil']])->assertOk();
    $id = $this->postJson('/office-api/maintenance/orders', ['asset_id' => $gen, 'kind' => 'planned', 'priority' => 'normal', 'title' => 'Monthly service'])->assertOk()->json('id');
    MaintenanceOrder::whereKey($id)->update(['checklist' => [['item' => 'Check oil', 'done' => false]]]);

    $this->actingAs($this->tech, 'web');
    $this->getJson('/office-api/maintenance/equipment')->assertOk();
    $this->postJson("/office-api/maintenance/equipment/{$gen}", ['name' => 'Renamed', 'owner' => 'own', 'category' => 'generator', 'branch_id' => $this->hq->id])->assertForbidden();
    expect($this->getJson("/office-api/maintenance/orders/{$id}")->assertOk()->json('checklist'))->toBe([['item' => 'Check oil', 'done' => false]]);
    $this->postJson("/office-api/maintenance/orders/{$id}/action", ['action' => 'complete', 'outcome' => 'Serviced'])->assertStatus(422); // a step is not done
    $this->postJson("/office-api/maintenance/orders/{$id}/action", ['action' => 'complete', 'outcome' => 'Serviced', 'checklist' => [['item' => 'Check oil', 'done' => true]]])->assertOk();
    expect(MaintenanceOrder::find($id)->status)->toBe('done');
    $this->postJson("/office-api/maintenance/orders/{$id}/action", ['action' => 'invoice'])->assertForbidden();
});
