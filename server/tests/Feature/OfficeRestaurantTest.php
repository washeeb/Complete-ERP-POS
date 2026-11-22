<?php

use App\Domain\Restaurant\RestaurantSetup;
use App\Models\DiningOrder;
use App\Models\DiningTable;
use App\Models\KitchenStation;
use App\Models\ModifierGroup;
use App\Models\Reservation;
use App\Security\ScopeType;
use Illuminate\Support\Str;

// The restaurant in the new back office (part 30): bookings, bills, floor plan, kitchen stations, modifiers, tips & voids.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->hq = $this->b['HQ'];
    enableModule($this->owner, $this->hq, 'restaurant');
    withMfa($this->owner);
    $this->t1 = app(RestaurantSetup::class)->saveTable(actorFor($this->owner), new DiningTable, ['branch_id' => $this->hq->id, 'code' => 'T1', 'seats' => 4]);
    $this->rice = makeProduct($this->owner, 'RICE', 'Jollof rice', retail: '60.00');
});

it('books a table, changes the booking, cancels one and marks a no-show', function () {
    $this->actingAs($this->owner, 'web');
    $form = $this->getJson("/office-api/restaurant/bookings/form?branch={$this->hq->id}")->assertOk()->json();
    expect($form['branch'])->toBe($this->hq->id)->and(collect($form['tables'])->pluck('id'))->toContain($this->t1->id);
    $at = now()->addDay()->setTime(19, 0)->format('Y-m-d H:i');
    $this->postJson('/office-api/restaurant/bookings', ['branch_id' => $this->hq->id, 'guest_name' => '', 'party_size' => 2, 'starts_at' => $at])->assertStatus(422);
    $id = $this->postJson('/office-api/restaurant/bookings', ['branch_id' => $this->hq->id, 'guest_name' => 'Kofi Boateng', 'phone' => '0241112222', 'party_size' => 4,
        'starts_at' => $at, 'dining_table_id' => $this->t1->id, 'note' => 'Birthday'])->assertOk()->json('id');
    $list = $this->getJson('/office-api/restaurant/bookings')->assertOk()->json();
    expect($list['total'])->toBe(1)->and($list['rows'][0])->toMatchArray(['name' => 'Kofi Boateng', 'guests' => 4, 'table' => 'T1', 'status' => 'booked', 'can_change' => true, 'can_no_show' => false]);

    $this->postJson("/office-api/restaurant/bookings/{$id}", ['guest_name' => 'Kofi Boateng', 'party_size' => 6, 'starts_at' => $at])->assertOk();
    expect(Reservation::find($id)->party_size)->toBe(6);
    $this->postJson("/office-api/restaurant/bookings/{$id}/action", ['action' => 'cancel'])->assertStatus(422);
    $this->postJson("/office-api/restaurant/bookings/{$id}/action", ['action' => 'cancel', 'reason' => 'Called to cancel'])->assertOk();
    expect(Reservation::find($id)->status)->toBe('cancelled');

    // A booking whose time has passed can be marked a no-show.
    $past = $this->postJson('/office-api/restaurant/bookings', ['branch_id' => $this->hq->id, 'guest_name' => 'Ama', 'party_size' => 2,
        'starts_at' => now()->addMinutes(30)->format('Y-m-d H:i')])->assertOk()->json('id');
    Reservation::whereKey($past)->update(['starts_at' => now()->subHour()]);
    expect(collect($this->getJson('/office-api/restaurant/bookings')->json('rows'))->firstWhere('id', $past)['can_no_show'])->toBeTrue();
    $this->postJson("/office-api/restaurant/bookings/{$past}/action", ['action' => 'no_show'])->assertOk();
    expect(Reservation::find($past)->status)->toBe('no_show');
    expect($this->getJson('/office-api/restaurant/bookings?status=no_show')->json('total'))->toBe(1);
});

it('keeps the floor plan, kitchen stations and modifiers, and archives and restores them', function () {
    $this->actingAs($this->owner, 'web');
    $tables = $this->getJson('/office-api/restaurant/tables')->assertOk()->json();
    expect($tables['next'][$this->hq->id])->toBe('T2')->and(collect($tables['rows'])->pluck('code')->all())->toBe(['T1']);
    $t2 = $this->postJson('/office-api/restaurant/tables', ['branch_id' => $this->hq->id, 'code' => 'T2', 'area' => 'Terrace', 'seats' => 6, 'pos_x' => 2, 'pos_y' => 1])->assertOk()->json('id');
    $this->postJson("/office-api/restaurant/tables/{$t2}", ['code' => 'T2', 'area' => 'Terrace', 'seats' => 8])->assertOk();
    expect(DiningTable::find($t2)->only(['seats', 'area', 'branch_id']))->toBe(['seats' => 8, 'area' => 'Terrace', 'branch_id' => $this->hq->id]);
    $this->postJson('/office-api/restaurant/archive', ['kind' => 'table', 'id' => $t2, 'reason' => 'x'])->assertStatus(422);
    $this->postJson('/office-api/restaurant/archive', ['kind' => 'table', 'id' => $t2, 'reason' => 'Terrace closed'])->assertOk();
    expect(collect($this->getJson('/office-api/restaurant/tables')->json('rows'))->pluck('code')->all())->toBe(['T1'])
        ->and(collect($this->getJson('/office-api/restaurant/tables?show=archived')->json('rows'))->pluck('code')->all())->toBe(['T2']);
    $this->postJson('/office-api/restaurant/archive', ['kind' => 'table', 'id' => $t2, 'restore' => true])->assertOk();
    expect(DiningTable::find($t2)->archived_at)->toBeNull();

    $sid = $this->postJson('/office-api/restaurant/stations', ['branch_id' => $this->hq->id, 'name' => 'Grill', 'auto_print' => true])->assertOk()->json('id');
    expect(KitchenStation::find($sid)->only(['code', 'auto_print']))->toBe(['code' => 'GRILL', 'auto_print' => true]);
    $this->postJson("/office-api/restaurant/stations/{$sid}", ['name' => 'Grill', 'auto_print' => false])->assertOk();
    expect(collect($this->getJson('/office-api/restaurant/stations')->json('rows'))->firstWhere('id', $sid)['auto_print'])->toBeFalse();

    expect(collect($this->getJson('/office-api/restaurant/modifiers/products?q=jollof')->assertOk()->json('results'))->pluck('id'))->toContain($this->rice->id);
    $this->postJson('/office-api/restaurant/modifiers', ['name' => 'Sides', 'min_choices' => 3, 'max_choices' => 1, 'options' => [['name' => 'Plantain']]])->assertStatus(422);
    $gid = $this->postJson('/office-api/restaurant/modifiers', ['name' => 'Sides', 'min_choices' => 1, 'max_choices' => 2, 'is_active' => true,
        'options' => [['name' => 'Plantain'], ['name' => 'Extra rice', 'product_id' => $this->rice->id]]])->assertOk()->json('id');
    $row = collect($this->getJson('/office-api/restaurant/modifiers')->assertOk()->json('rows'))->firstWhere('id', $gid);
    expect($row)->toMatchArray(['name' => 'Sides', 'rule' => '1–2', 'choices' => 'Plantain, Extra rice', 'active' => true])
        ->and($row['values']['options'][1])->toMatchArray(['name' => 'Extra rice', 'product_id' => $this->rice->id]);
    $opts = $row['values']['options'];
    $this->postJson("/office-api/restaurant/modifiers/{$gid}", ['name' => 'Sides', 'min_choices' => 0, 'max_choices' => 1, 'is_active' => false,
        'options' => [$opts[0], ['name' => 'Salad']]])->assertOk();
    expect(ModifierGroup::find($gid)->options()->orderBy('sort')->pluck('name')->all())->toBe(['Plantain', 'Salad'])->and(ModifierGroup::find($gid)->is_active)->toBeFalse();
    $this->postJson('/office-api/restaurant/archive', ['kind' => 'modifier', 'id' => $gid, 'reason' => 'Menu changed'])->assertOk();
    expect(ModifierGroup::find($gid)->archived_at)->not->toBeNull();
});

it('lists bills with their voids, and shows tips and voids for a period', function () {
    $o = DiningOrder::forceCreate(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'number' => 'HQ-T-0001', 'branch_id' => $this->hq->id, 'kind' => 'table',
        'dining_table_id' => $this->t1->id, 'covers' => 2, 'waiter_name' => 'Efua', 'status' => 'open', 'opened_at' => now()]);
    $unit = $this->rice->units->first();
    foreach ([['served', null, null], ['void', 'Customer changed mind', 'Ama Owner']] as [$status, $why, $by]) {
        $o->items()->forceCreate(['id' => (string) Str::uuid7(), 'product_id' => $this->rice->id, 'product_uom_id' => $unit->id, 'name' => 'Jollof rice', 'qty' => '1', 'status' => $status,
            'added_by_name' => 'Efua', 'added_at' => now(), 'void_reason' => $why, 'voided_by_name' => $by]);
    }
    $this->actingAs($this->owner, 'web');
    $bills = $this->getJson('/office-api/restaurant/bills')->assertOk()->json();
    expect($bills['total'])->toBe(1)->and($bills['rows'][0])->toMatchArray(['number' => 'HQ-T-0001', 'waiter' => 'Efua', 'status_label' => 'Open', 'voids' => 1]);
    expect($this->getJson('/office-api/restaurant/bills?status=closed')->json('total'))->toBe(0);
    $one = $this->getJson("/office-api/restaurant/bills/{$o->id}")->assertOk()->json();
    expect($one['items'])->toHaveCount(2)->and($one['items'][1])->toMatchArray(['qty' => '1', 'status' => 'void', 'void' => 'Customer changed mind (Ama Owner)']);

    $r = $this->getJson('/office-api/restaurant/tips-voids?from='.now()->startOfMonth()->toDateString().'&to='.now()->toDateString())->assertOk()->json();
    expect($r['voids'])->toHaveCount(1)->and($r['voids'][0])->toMatchArray(['bill' => 'HQ-T-0001', 'item' => '1 × Jollof rice', 'reason' => 'Customer changed mind', 'by' => 'Ama Owner'])
        ->and($r['tips'])->toBe([]);

    // A cashier without the restaurant rights sees none of it.
    $cashier = staff($this->owner, 'yaa', 3, 'cashier', ScopeType::Branch, $this->hq->id);
    $this->actingAs($cashier, 'web');
    $this->getJson('/office-api/restaurant/tables')->assertForbidden();
    $this->getJson('/office-api/restaurant/tips-voids')->assertForbidden();
});
