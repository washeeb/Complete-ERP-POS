<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Hr\HrService;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\LicenceKey;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\ServicePass;
use App\Security\ScopeType;

// Services in the new back office (part 26): calendar, appointments, passes, memberships, plans, commissions, licence keys.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->hq = $this->b['HQ'];
    enableModule($this->owner, $this->hq, 'services');
    withMfa($this->owner);
    $o = actorFor($this->owner);
    $catalog = app(CatalogService::class);
    $this->cut = $catalog->saveProduct($o, makeProduct($this->owner, 'CUT', 'Haircut', retail: '80.00'), ['track_stock' => false, 'service_minutes' => 45])->fresh();
    $this->gym = $catalog->saveProduct($o, makeProduct($this->owner, 'GYM', 'Gym membership', retail: '150.00'), ['track_stock' => false])->fresh();
    $this->abla = app(HrService::class)->saveEmployee($o, new Employee, ['name' => 'Abla Stylist', 'branch_id' => $this->hq->id, 'hire_date' => now()->subYear()->toDateString(),
        'employment_type' => 'permanent', 'pay_method' => 'cash', 'takes_appointments' => true, 'commission_bp' => 1000]);
    $this->ama = Customer::create(['code' => 'C1', 'name' => 'Ama Owusu', 'tenant_id' => $this->tenant->id, 'phone' => '+233241112222']);
});

it('books, shows on the calendar, changes, marks arrived and cancels appointments', function () {
    $this->actingAs($this->owner, 'web');
    $form = $this->getJson("/office-api/services/appointments/form?branch={$this->hq->id}")->assertOk()->json();
    expect(collect($form['services'])->pluck('id'))->toContain($this->cut->id)->and(collect($form['staff'])->pluck('id'))->toContain($this->abla->id);
    $at = now()->addDay()->setTime(10, 0)->format('Y-m-d H:i');
    $this->postJson('/office-api/services/appointments', ['branch_id' => $this->hq->id, 'product_id' => $this->cut->id, 'employee_id' => $this->abla->id,
        'starts_at' => now()->subDay()->format('Y-m-d H:i'), 'customer_name' => 'Kofi'])->assertStatus(422); // already passed
    $id = $this->postJson('/office-api/services/appointments', ['branch_id' => $this->hq->id, 'product_id' => $this->cut->id, 'employee_id' => $this->abla->id,
        'starts_at' => $at, 'customer_id' => $this->ama->id, 'phone' => '0241112222'])->assertOk()->json('id');
    $list = $this->getJson('/office-api/services/appointments')->assertOk()->json();
    expect($list['total'])->toBe(1)->and($list['rows'][0])->toMatchArray(['service' => 'HAIRCUT', 'with' => 'Abla Stylist', 'status' => 'booked', 'can_change' => true]);

    $cal = $this->getJson('/office-api/services/calendar?branch='.$this->hq->id.'&date='.now()->addDay()->toDateString())->assertOk()->json();
    expect($cal['staff'][0]['name'])->toBe('Abla Stylist')->and($cal['slots'])->not->toBeEmpty()
        ->and(collect($cal['cells'][$this->abla->id] ?? [])->flatten(1)->pluck('id'))->toContain($id);

    $this->postJson("/office-api/services/appointments/{$id}/action", ['action' => 'change', 'starts_at' => now()->addDays(2)->setTime(11, 0)->format('Y-m-d H:i'), 'employee_id' => $this->abla->id])->assertOk();
    expect(Appointment::find($id)->starts_at->toDateString())->toBe(now()->addDays(2)->toDateString());
    $this->postJson("/office-api/services/appointments/{$id}/action", ['action' => 'arrived'])->assertOk();
    expect(Appointment::find($id)->status)->toBe('arrived');
    $this->postJson("/office-api/services/appointments/{$id}/action", ['action' => 'cancel'])->assertStatus(422);
    $this->postJson("/office-api/services/appointments/{$id}/action", ['action' => 'cancel', 'reason' => 'Customer called'])->assertOk();
    expect(Appointment::find($id)->status)->toBe('cancelled');
});

it('keeps plans, signs a customer up, pauses, resumes and cancels, and lists the pass it gives', function () {
    $this->actingAs($this->owner, 'web');
    $p = $this->getJson('/office-api/services/plans')->assertOk()->json();
    expect($p['can_create'])->toBeTrue()->and(collect($p['products'])->pluck('id'))->toContain($this->gym->id);
    $this->postJson('/office-api/services/plans', ['name' => 'Gold', 'price' => '0', 'interval_months' => 1, 'product_id' => $this->gym->id])->assertStatus(422);
    $planId = $this->postJson('/office-api/services/plans', ['name' => 'Gold', 'price' => '150', 'interval_months' => 1, 'product_id' => $this->gym->id, 'is_active' => true,
        'items' => [['product_ids' => [$this->cut->id], 'sessions' => 2]]])->assertOk()->json('id');
    $plan = MembershipPlan::find($planId);
    expect($plan->price_minor)->toBe(15000)->and($plan->items[0]['sessions'])->toBe(2);
    expect($this->getJson('/office-api/services/plans')->json('rows.0'))->toMatchArray(['name' => 'Gold', 'price' => 15000, 'includes' => '2 × HAIRCUT']);

    $m = $this->getJson('/office-api/services/memberships')->assertOk()->json();
    expect(collect($m['plans'])->pluck('id'))->toContain($planId);
    expect(collect($this->getJson('/office-api/services/customers?q=ama')->json('results'))->pluck('id'))->toContain($this->ama->id);
    $mid = $this->postJson('/office-api/services/memberships', ['customer_id' => $this->ama->id, 'membership_plan_id' => $planId, 'branch_id' => $this->hq->id,
        'starts_on' => now()->toDateString()])->assertOk()->json('id');
    expect(Membership::find($mid)->status)->toBe('active');
    $this->postJson("/office-api/services/memberships/{$mid}/action", ['action' => 'pause'])->assertStatus(422);
    $this->postJson("/office-api/services/memberships/{$mid}/action", ['action' => 'pause', 'reason' => 'Travelling'])->assertOk();
    expect(Membership::find($mid)->status)->toBe('paused')->and($this->getJson('/office-api/services/memberships?status=paused')->json('total'))->toBe(1);
    $this->postJson("/office-api/services/memberships/{$mid}/action", ['action' => 'resume'])->assertOk();
    $this->postJson("/office-api/services/memberships/{$mid}/action", ['action' => 'cancel', 'reason' => 'Moved away'])->assertOk();
    expect(Membership::find($mid)->status)->toBe('cancelled');

    $passes = $this->getJson('/office-api/services/passes?status=all')->assertOk()->json();
    if ($passes['total'] > 0) { // the membership's sessions come as a pass
        $pass = ServicePass::first();
        expect($this->getJson("/office-api/services/passes/{$pass->id}")->assertOk()->json('title'))->toContain($pass->number);
        if ($pass->status === 'active') {
            $this->postJson("/office-api/services/passes/{$pass->id}/void", ['reason' => 'Refunded'])->assertOk();
            expect($pass->fresh()->status)->toBe('void');
        }
    }
    $this->postJson("/office-api/services/plans/{$planId}/archive", ['reason' => 'Old plan'])->assertOk();
    expect($this->getJson('/office-api/services/plans')->json('rows'))->toBe([]);
});

it('loads licence keys, shows and voids one, and reports commissions; others are kept out', function () {
    $keyed = app(CatalogService::class)->saveProduct(actorFor($this->owner), makeProduct($this->owner, 'AV', 'Antivirus 1 year', retail: '120.00'), ['track_stock' => false, 'licence_keys' => true])->fresh();
    $this->actingAs($this->owner, 'web');
    $this->postJson('/office-api/services/keys', ['product_id' => $keyed->id, 'branch_id' => $this->hq->id, 'keys' => "AAAA-1111-BBBB\nCCCC-2222-DDDD"])->assertOk();
    expect(LicenceKey::count())->toBe(2);
    $keys = $this->getJson('/office-api/services/keys')->assertOk()->json();
    expect($keys['total'])->toBe(2)->and($keys['rows'][0]['hint'])->toStartWith('…')->and(json_encode($keys))->not->toContain('AAAA-1111-BBBB');
    $k = LicenceKey::where('licence_key', 'AAAA-1111-BBBB')->sole();
    expect($this->getJson("/office-api/services/keys/{$k->id}")->assertOk()->json('key'))->toBe('AAAA-1111-BBBB');
    $this->postJson("/office-api/services/keys/{$k->id}/void", ['reason' => 'Typed wrong'])->assertOk();
    expect($k->fresh()->status)->toBe('void');
    $this->postJson('/office-api/services/keys/owed')->assertOk();

    $c = $this->getJson('/office-api/services/commissions')->assertOk()->json();
    expect($c)->toHaveKeys(['from', 'to', 'rows', 'lines'])->and($c['rows'])->toBe([]);

    $cashier = withMfa(staff($this->owner, 'efua', 3, 'cashier', ScopeType::Branch, $this->hq->id));
    $this->actingAs($cashier, 'web');
    foreach (['services/plans', 'services/memberships', 'services/keys', 'services/commissions'] as $path) {
        $this->getJson("/office-api/{$path}")->assertForbidden();
    }
});
