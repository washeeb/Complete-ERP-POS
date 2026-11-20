<?php

use App\Models\AccessRequest;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

// Users in the new back office (settings and admin, part 1): the list, one user, Add, Change, PIN, staff card,
// two-step reset, archive and restore, and access grants, through AccessService; an IT Admin's change goes for approval.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->hq = $this->b['HQ'];
    withMfa($this->owner);
});

it('adds a user, changes them, grants and revokes access, and finds them', function () {
    $this->actingAs($this->owner, 'web');
    $o = $this->getJson('/office-api/users/options')->assertOk()->json();
    expect($o['can_manage'])->toBeTrue()->and($o['can_grant'])->toBeTrue()->and(collect($o['levels'])->pluck('id')->all())->not->toContain('7')
        ->and(collect($o['roles'])->pluck('label')->implode(' '))->not->toContain('Superadmin')->and($o['places']['branch'])->not->toBeEmpty();

    $base = ['name' => 'Akua Mensah', 'username' => 'akua', 'email' => 'akua@demo.test', 'signin_method' => 'default', 'security_level' => 2];
    $this->postJson('/office-api/users', $base + ['password' => 'short'])->assertStatus(422);
    $this->postJson('/office-api/users', ['username' => 'bad name!'] + $base + ['password' => 'correct-horse-battery'])->assertStatus(422);
    $new = $this->postJson('/office-api/users', $base + ['password' => 'correct-horse-battery'])->assertOk()->json();
    $id = $new['user']['id'];
    expect($new['user']['open'])->toBe("/office/users/{$id}")->and(Hash::check('correct-horse-battery', User::find($id)->password))->toBeTrue();

    $this->postJson("/office-api/users/{$id}", ['name' => 'Akua Mensah-Boateng'] + $base + ['reason' => 'Married'])->assertOk();
    expect(User::find($id))->name->toBe('Akua Mensah-Boateng')->and(Hash::check('correct-horse-battery', User::find($id)->password))->toBeTrue(); // blank password kept

    $this->postJson("/office-api/users/{$id}/pin", ['pin' => '1234'])->assertStatus(422); // too easy
    $this->postJson("/office-api/users/{$id}/pin", ['pin' => '4831'])->assertOk();
    $card = $this->postJson("/office-api/users/{$id}/card", ['layout' => 'a4'])->assertOk()->json('print');
    expect($card)->toContain('staff-cards')->and(User::find($id)->badge_hash)->not->toBeNull();
    $this->postJson("/office-api/users/{$id}/card-lost")->assertOk();
    expect(User::find($id)->badge_hash)->toBeNull();

    $teller = Role::where('key', 'teller')->sole();
    $this->postJson("/office-api/users/{$id}/access", ['role_id' => $teller->id, 'scope_type' => 'branch'])->assertStatus(422); // which branch
    $this->postJson("/office-api/users/{$id}/access", ['role_id' => $teller->id, 'scope_type' => 'branch', 'scope_id' => $this->hq->id, 'expires_at' => now()->addDay()->toIso8601String()])
        ->assertStatus(422); // temporary needs a reason
    $this->postJson("/office-api/users/{$id}/access", ['role_id' => $teller->id, 'scope_type' => 'branch', 'scope_id' => $this->hq->id, 'limit_discount' => '5'])->assertOk();
    $u = $this->getJson("/office-api/users/{$id}")->assertOk()->json('user');
    expect($u)->toMatchArray(['name' => 'Akua Mensah-Boateng', 'pin' => true, 'card' => false, 'can_edit' => true, 'can_archive' => true])
        ->and($u['grants'][0])->toMatchArray(['role' => $teller->name, 'status' => 'Active', 'can_revoke' => true])
        ->and($u['grants'][0]['applies_to'])->toContain($this->hq->name)->and($u['grants'][0]['limits'])->toContain('5%')
        ->and($u['history'][0]['name'])->toBe(['Akua Mensah', 'Akua Mensah-Boateng']);

    $this->postJson("/office-api/users/{$id}/access/{$u['grants'][0]['id']}/revoke", ['reason' => ''])->assertStatus(422);
    $this->postJson("/office-api/users/{$id}/access/{$u['grants'][0]['id']}/revoke", ['reason' => 'Moved to stores'])->assertOk();
    expect(RoleAssignment::find($u['grants'][0]['id'])->revoked_at)->not->toBeNull();

    $l = $this->getJson('/office-api/users?q=akua')->assertOk()->json();
    expect($l['total'])->toBe(1)->and($l['rows'][0])->toMatchArray(['username' => 'akua', 'level' => 2, 'pin' => true, 'archived' => false]);

    $this->postJson("/office-api/users/{$id}/archive", ['reason' => 'Left the company'])->assertOk();
    expect($this->getJson('/office-api/users?q=akua')->json('total'))->toBe(0)->and($this->getJson('/office-api/users?q=akua&show=archived')->json('total'))->toBe(1);
    $this->postJson("/office-api/users/{$id}/restore")->assertOk();
    expect($this->getJson('/office-api/users?q=akua')->json('total'))->toBe(1);
});

it('sends an IT Admin\'s change for approval and keeps the request, and keeps others out', function () {
    $clerk = staff($this->owner, 'cash', 2); // before the IT Admin: from then on, users are IT's, not the owner's
    $it = withMfa(staff($this->owner, 'it', 4, 'it_admin'));
    $this->actingAs($it, 'web');
    expect($this->getJson('/office-api/users/options')->assertOk()->json('can_grant'))->toBeTrue();

    $r = $this->postJson('/office-api/users', ['name' => 'Kojo', 'username' => 'kojo', 'email' => 'kojo@demo.test', 'signin_method' => 'default', 'security_level' => 2,
        'password' => 'correct-horse-battery'])->assertOk()->json();
    expect($r['pending'])->toBeTrue()->and($r['message'])->toContain('approves it')
        ->and(User::where('username', 'kojo')->exists())->toBeFalse()->and(AccessRequest::where('kind', 'user.create')->count())->toBe(1);
    $g = $this->postJson("/office-api/users/{$clerk->id}/access", ['role_id' => Role::where('key', 'teller')->value('id'), 'scope_type' => 'branch', 'scope_id' => $this->hq->id])->assertOk()->json();
    expect($g['pending'])->toBeTrue()->and($clerk->roleAssignments()->count())->toBe(0)->and(AccessRequest::where('kind', 'access.grant')->count())->toBe(1);
    $this->postJson('/office-api/users', ['name' => 'Big', 'username' => 'big', 'email' => 'big@demo.test', 'signin_method' => 'default', 'security_level' => 5,
        'password' => 'correct-horse-battery'])->assertStatus(422); // above IT's own level
    $this->postJson("/office-api/users/{$this->owner->id}", ['name' => 'X', 'username' => 'x', 'email' => 'x@demo.test', 'signin_method' => 'default', 'security_level' => 2])
        ->assertForbidden(); // above IT's level

    $this->actingAs($clerk, 'web')->getJson('/office-api/users')->assertForbidden();
    $this->postJson("/office-api/users/{$it->id}/pin", ['pin' => '4831'])->assertForbidden();
});
