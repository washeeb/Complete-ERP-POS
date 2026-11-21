<?php

use App\Models\Promotion;
use App\Security\ScopeType;

// Promotions in the new back office (catalog and pricing setup, part 9), through PromotionService.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    withMfa($this->owner);
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    $this->keeper = staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->branches['HQ']->id);
    $this->cashier = staff($this->owner, 'esi', 2, 'teller', ScopeType::Branch, $this->branches['HQ']->id);
});

it('adds a buy-2-get-1-free and a coupon as people write them, lists and changes them', function () {
    $this->actingAs($this->owner, 'web');
    $o = $this->getJson('/office-api/promotions/options')->assertOk()->json();
    expect(collect($o['types'])->pluck('id')->all())->toContain('buy_x_get_y', 'coupon');
    expect(collect($this->getJson('/office-api/promotions/products?q=milo')->json('rows'))->pluck('id')->all())->toContain($this->milo->id);

    $bogo = $this->postJson('/office-api/promotions', ['name' => 'Milo 3 for 2', 'type' => 'buy_x_get_y', 'buy_qty' => 2, 'get_qty' => 1, 'percent' => '100',
        'amount' => '9', 'product_ids' => [$this->milo->id], 'days' => [5, 6], 'time_from' => '16:00', 'time_to' => '18:00', 'is_active' => true])->assertOk()->json('id');
    $coupon = $this->postJson('/office-api/promotions', ['name' => 'Back to school', 'type' => 'coupon', 'coupon_code' => 'school5', 'amount' => '5', 'min_spend' => '50',
        'is_active' => true])->assertOk()->json('id');
    $b = Promotion::find($bogo);
    $c = Promotion::find($coupon);
    expect($b->value_bp)->toBe(10000)->and($b->value_minor)->toBe(0) // the amount is not asked for this kind
        ->and($b->days)->toBe([5, 6])->and($c->coupon_code)->toBe('SCHOOL5')->and($c->value_minor)->toBe(500)->and($c->min_spend_minor)->toBe(5000);

    $rows = collect($this->getJson('/office-api/promotions')->assertOk()->json('rows'));
    expect($rows->firstWhere('id', $bogo))->toMatchArray(['offer' => 'Buy 2 get 1 free', 'when' => '16:00–18:00', 'running' => true, 'can_edit' => true])
        ->and($rows->firstWhere('id', $coupon)['coupon'])->toBe('SCHOOL5');
    expect($this->getJson('/office-api/promotions?q=school')->json('total'))->toBe(1);

    $v = $this->getJson("/office-api/promotions/{$coupon}")->assertOk()->json('values');
    expect($v)->toMatchArray(['amount' => '5.00', 'min_spend' => '50.00', 'coupon_code' => 'SCHOOL5']);
    $this->postJson("/office-api/promotions/{$coupon}", ['amount' => '7.5'] + $v)->assertOk();
    expect($c->fresh()->value_minor)->toBe(750);
});

it('refuses a promotion without its numbers or with a used coupon code, archives and restores, and keeps others out', function () {
    $this->actingAs($this->owner, 'web');
    $this->postJson('/office-api/promotions', ['name' => 'Half done', 'type' => 'bundle', 'bundle_qty' => 3])->assertStatus(422)
        ->assertJsonPath('detail', fn ($m) => str_contains($m, 'bundle price'));
    $id = $this->postJson('/office-api/promotions', ['name' => 'Ten off', 'type' => 'coupon', 'coupon_code' => 'TEN', 'percent' => '10'])->assertOk()->json('id');
    $this->postJson('/office-api/promotions', ['name' => 'Again', 'type' => 'coupon', 'coupon_code' => 'ten', 'percent' => '5'])->assertStatus(422);
    $this->postJson('/office-api/promotions', ['name' => 'Odd hours', 'type' => 'percent', 'percent' => '5', 'time_from' => '16:00'])->assertStatus(422);

    $this->postJson("/office-api/promotions/{$id}/archive", ['reason' => 'x'])->assertStatus(422);
    $this->postJson("/office-api/promotions/{$id}/archive", ['reason' => 'Term over'])->assertOk();
    expect(Promotion::find($id)->archived_at)->not->toBeNull()
        ->and($this->getJson('/office-api/promotions')->json('total'))->toBe(0)
        ->and($this->getJson('/office-api/promotions?show=archived&running=both')->json('rows.0.can_restore'))->toBeTrue();
    $this->postJson("/office-api/promotions/{$id}/restore")->assertOk();
    expect(Promotion::find($id)->archived_at)->toBeNull();

    $this->actingAs($this->keeper, 'web');
    expect($this->getJson('/office-api/promotions')->assertOk()->json('can_create'))->toBeFalse(); // sees them (catalog), as the current list
    $this->postJson('/office-api/promotions', ['name' => 'Mine', 'type' => 'percent', 'percent' => '5'])->assertForbidden();
    $this->actingAs($this->cashier, 'web');
    $this->getJson('/office-api/promotions')->assertForbidden();
    $this->postJson('/office-api/promotions', ['name' => 'Mine', 'type' => 'percent', 'percent' => '5'])->assertForbidden();
});
