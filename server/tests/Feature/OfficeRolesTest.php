<?php

use App\Models\AccessRequest;
use App\Models\Role;

// Roles and access requests in the new back office (settings and admin, part 2): the roles, one role, Add, Change,
// Archive and Restore through AccessService; IT Admin's changes wait as requests the owner approves or turns down.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    withMfa($this->owner);
});

it('adds a role, changes it, lists it, archives and restores it', function () {
    $this->actingAs($this->owner, 'web');
    $f = $this->getJson('/office-api/roles/form')->assertOk()->json();
    expect(collect($f['levels'])->pluck('id')->all())->not->toContain('7')
        ->and(collect($f['areas'])->flatMap(fn ($a) => collect($a['permissions'])->pluck('key'))->all())->toContain('sales.view');

    $this->postJson('/office-api/roles', ['name' => 'Night clerk', 'security_level' => 2, 'permissions' => ['no.such.thing']])->assertStatus(422);
    $new = $this->postJson('/office-api/roles', ['name' => 'Night clerk', 'security_level' => 2, 'description' => 'Counts the shop at night', 'permissions' => ['sales.view']])
        ->assertOk()->json();
    $id = $new['role']['id'];
    expect($new['role']['open'])->toBe("/office/roles/{$id}")->and(Role::find($id)->permissionKeys())->toBe(['sales.view'])
        ->and(Role::find($id)->key)->toStartWith('night_clerk_');

    $this->postJson("/office-api/roles/{$id}", ['name' => 'Night clerk', 'security_level' => 2, 'permissions' => ['sales.view', 'stock.view'], 'is_technical' => true])->assertStatus(422);
    $this->postJson("/office-api/roles/{$id}", ['name' => 'Night stock clerk', 'security_level' => 2, 'permissions' => ['sales.view', 'stock.view'], 'reason' => 'Also counts'])->assertOk();
    $r = $this->getJson("/office-api/roles/{$id}")->assertOk()->json('role');
    expect($r)->toMatchArray(['name' => 'Night stock clerk', 'level' => 2, 'permissions' => 2, 'technical' => false, 'seeded' => false, 'can_edit' => true, 'holders' => 0])
        ->and($r['keys'])->toEqualCanonicalizing(['sales.view', 'stock.view']);

    $l = $this->getJson('/office-api/roles?q=night')->assertOk()->json();
    expect($l['total'])->toBe(1)->and($l['rows'][0])->toMatchArray(['name' => 'Night stock clerk', 'permissions' => 2])->and($l['can_manage'])->toBeTrue();
    $this->postJson("/office-api/roles/{$id}/archive", ['reason' => 'No night shift'])->assertOk();
    expect($this->getJson('/office-api/roles?q=night')->json('total'))->toBe(0)->and($this->getJson('/office-api/roles?q=night&show=archived')->json('total'))->toBe(1);
    $this->postJson("/office-api/roles/{$id}/restore")->assertOk();
    expect(Role::find($id)->archived_at)->toBeNull();
});

it('keeps an IT Admin\'s role change as a request; the owner approves one and turns one down; IT withdraws one', function () {
    $clerk = staff($this->owner, 'cash', 2);
    $it = withMfa(staff($this->owner, 'it', 4, 'it_admin'));
    $sup = Role::where('key', 'supervisor')->sole();
    $before = $sup->permissionKeys();

    $this->actingAs($it, 'web');
    $r = $this->postJson("/office-api/roles/{$sup->id}", ['name' => 'Shift supervisor', 'security_level' => $sup->security_level, 'permissions' => $before])->assertOk()->json();
    expect($r['pending'])->toBeTrue()->and($sup->fresh()->name)->not->toBe('Shift supervisor');
    $this->postJson("/office-api/roles/{$sup->id}", ['name' => 'Floor lead', 'security_level' => $sup->security_level, 'permissions' => $before])->assertOk();
    $this->postJson('/office-api/users/'.$clerk->id.'/pin', ['pin' => '4831'])->assertOk();
    $mine = $this->getJson('/office-api/access-requests?status=pending')->assertOk()->json();
    expect($mine['total'])->toBe(3)->and($mine['rows'][0])->toMatchArray(['can_decide' => false, 'can_withdraw' => true]);
    [$pin, $floor, $shift] = array_column($mine['rows'], 'id');
    $this->postJson("/office-api/access-requests/{$pin}/withdraw")->assertOk();
    $this->postJson("/office-api/access-requests/{$shift}/approve")->assertForbidden(); // not your own

    $this->actingAs($this->owner, 'web');
    $o = $this->getJson('/office-api/access-requests?status=pending')->assertOk()->json();
    expect($o['total'])->toBe(2)->and($o['rows'][0]['can_decide'])->toBeTrue();
    $this->postJson("/office-api/access-requests/{$floor}/reject", ['note' => ''])->assertStatus(422);
    $this->postJson("/office-api/access-requests/{$floor}/reject", ['note' => 'Keep the usual name'])->assertOk();
    expect($this->postJson("/office-api/access-requests/{$shift}/approve")->assertOk()->json('message'))->toBe('Approved and done.');
    expect($sup->fresh()->name)->toBe('Shift supervisor')
        ->and(AccessRequest::find($floor)->status)->toBe('rejected')->and(AccessRequest::find($pin)->status)->toBe('withdrawn');

    $this->actingAs($clerk, 'web')->getJson('/office-api/roles')->assertForbidden();
    $this->getJson('/office-api/access-requests')->assertForbidden();
});
