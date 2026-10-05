<?php

use App\Domain\Access\AccessService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Security\ScopeType;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->authz = app(Authorizer::class);
    $this->access = app(AccessService::class);
});

it('gives the owner every permission company-wide', function () {
    expect($this->authz->allows($this->owner, 'setup.company.manage'))->toBeTrue()
        ->and($this->authz->allows($this->owner, 'admin.roles.manage', $this->branches['BA']))->toBeTrue();
});

it('limits a branch-scoped role to its own branch and what is inside it', function () {
    $mgr = staff($this->owner, 'kofi', 4, 'branch_manager', ScopeType::Branch, $this->branches['BA']->id);
    $tillInA = Till::where('branch_id', $this->branches['BA']->id)->first();
    $whInHq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();

    expect($this->authz->allows($mgr, 'pos.access', $this->branches['BA']))->toBeTrue()
        ->and($this->authz->allows($mgr, 'pos.access', $tillInA))->toBeTrue()
        ->and($this->authz->allows($mgr, 'pos.access', $this->branches['HQ']))->toBeFalse()
        ->and($this->authz->allows($mgr, 'pos.access', $whInHq))->toBeFalse()
        // missing scope is never global scope
        ->and($this->authz->allows($mgr, 'pos.access'))->toBeFalse()
        ->and($this->authz->branchIds($mgr, 'pos.access'))->toBe([$this->branches['BA']->id]);
});

it('gives a till-scoped cashier only that till, not the whole branch', function () {
    $till = Till::where('branch_id', $this->branches['BA']->id)->orderBy('code')->first();
    $other = Till::where('branch_id', $this->branches['BA']->id)->orderBy('code')->skip(1)->first();
    $cashier = staff($this->owner, 'esi', 2, 'cashier', ScopeType::Till, $till->id);

    expect($this->authz->allows($cashier, 'pos.access', $till))->toBeTrue()
        ->and($this->authz->allows($cashier, 'pos.access', $other))->toBeFalse()
        ->and($this->authz->allows($cashier, 'pos.access', $this->branches['BA']))->toBeFalse();
});

it('denies everything with no assignment, and denies unknown permissions', function () {
    $nobody = staff($this->owner, 'yaw', 2);

    expect($this->authz->allows($nobody, 'pos.access', $this->branches['HQ']))->toBeFalse()
        ->and($this->authz->allows($this->owner, 'made.up.permission'))->toBeFalse();
});

it('stops honouring expired, revoked or inactive access', function () {
    $c = staff($this->owner, 'abena', 2, 'cashier', ScopeType::Branch, $this->branches['HQ']->id);
    expect($this->authz->allows($c, 'pos.access', $this->branches['HQ']))->toBeTrue();

    $grant = RoleAssignment::where('user_id', $c->id)->first();
    $this->access->revoke(actorFor($this->owner), $grant, 'Left the company');
    expect(app(Authorizer::class)->allows($c->fresh(), 'pos.access', $this->branches['HQ']))->toBeFalse();

    $t = staff($this->owner, 'temp', 3);
    $this->access->grant(actorFor($this->owner), $t, Role::where('key', 'supervisor')->first(), ScopeType::Branch,
        $this->branches['HQ']->id, expiresAt: now()->addHour(), reason: 'Cover for supervisor on leave');
    $this->travel(2)->hours();
    $this->authz->forget();
    expect($this->authz->allows($t->fresh(), 'pos.access', $this->branches['HQ']))->toBeFalse();
});

it('ignores a role above the user\'s security ceiling even if it is in the table', function () {
    $c = staff($this->owner, 'kwame', 2);
    // Simulate a bad row written outside the service.
    DB::table('role_assignments')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'user_id' => $c->id,
        'role_id' => Role::where('key', 'general_manager')->value('id'), 'scope_type' => 'tenant',
        'scope_id' => $this->tenant->id, 'limits' => '{}', 'starts_at' => now(), 'granted_by' => $this->owner->id,
        'granted_by_name' => 'x', 'origin_node' => 'TEST', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($this->authz->allows($c, 'setup.company.manage'))->toBeFalse();
});

it('refuses self-granting', function () {
    $this->access->grant(actorFor($this->owner), $this->owner, Role::where('key', 'cashier')->first(), ScopeType::Tenant, $this->tenant->id);
})->throws(DomainException::class, 'yourself');

it('refuses granting a role above the person\'s level', function () {
    $c = staff($this->owner, 'efua', 2);
    $this->access->grant(actorFor($this->owner), $c, Role::where('key', 'branch_manager')->first(), ScopeType::Tenant, $this->tenant->id);
})->throws(DomainException::class, 'only cleared to L2');

it('keeps IT Admin technical: it cannot hand out business access', function () {
    $it = staff($this->owner, 'it', 4, 'it_admin');
    $c = staff($this->owner, 'cash', 2);

    expect($this->authz->allows($it, 'admin.users.manage'))->toBeTrue()
        ->and($this->authz->allows($it, 'pos.access', $this->branches['HQ']))->toBeFalse()
        ->and($this->authz->allows($it, 'setup.company.manage'))->toBeFalse();

    $this->access->grant(actorFor($it), $c, Role::where('key', 'cashier')->first(), ScopeType::Branch, $this->branches['HQ']->id);
})->throws(AccessDenied::class);

it('refuses adding business permissions to a technical role', function () {
    $role = Role::where('key', 'it_admin')->first();
    $this->access->saveRole(actorFor($this->owner), $role, [], [...$role->permissionKeys(), 'pos.access']);
})->throws(DomainException::class, 'technical role');

it('refuses adding a permission the editor does not hold company-wide', function () {
    $mgr = staff($this->owner, 'gm', 5, 'general_manager');
    // give the GM role-editing rights at one branch only
    $editor = Role::create(['key' => 'role_editor', 'name' => 'Role editor', 'security_level' => 5]);
    $this->access->saveRole(actorFor($this->owner), $editor, ['security_level' => 5], ['admin.roles.manage']);
    $this->access->grant(actorFor($this->owner), $mgr, $editor, ScopeType::Tenant, $this->tenant->id);

    $bm = Role::where('key', 'branch_manager')->first();
    $this->access->saveRole(actorFor($mgr->fresh()), $bm, [], [...$bm->permissionKeys(), 'audit.verify']);
})->throws(AccessDenied::class);

it('refuses creating a user above the creator\'s own level', function () {
    $it = staff($this->owner, 'it2', 4, 'it_admin');
    $this->access->createUser(actorFor($it), ['name' => 'Boss', 'username' => 'boss', 'email' => 'b@demo.test',
        'password' => 'correct-horse-battery', 'security_level' => 5]);
})->throws(AccessDenied::class);

it('requires a reason for temporary access', function () {
    $s = staff($this->owner, 'sup', 3);
    $this->access->grant(actorFor($this->owner), $s, Role::where('key', 'supervisor')->first(), ScopeType::Tenant,
        $this->tenant->id, expiresAt: now()->addDay());
})->throws(DomainException::class, 'reason');

it('reads numeric limits from the covering grant', function () {
    $s = staff($this->owner, 'sup2', 3, 'supervisor', ScopeType::Branch, $this->branches['HQ']->id, ['discount_pct' => 10]);

    expect($this->authz->allowsAmount($s, 'pos.access', $this->branches['HQ'], 'discount_pct', 10))->toBeTrue()
        ->and($this->authz->allowsAmount($s, 'pos.access', $this->branches['HQ'], 'discount_pct', 11))->toBeFalse()
        ->and($this->authz->allowsAmount($s, 'pos.access', $this->branches['HQ'], 'refund_minor', 1))->toBeFalse();
});

it('isolates companies from each other', function () {
    [$other, $otherOwner] = installCompany('Other Co', 'boss@other.test');
    app(TenantContext::class)->set($other->id);
    $otherBranches = setUpBranches($otherOwner, [['code' => 'XX', 'name' => 'Other HQ', 'is_hq' => true, 'tills' => 1]]);

    expect($this->authz->allows($this->owner, 'setup.company.manage', $otherBranches['XX']))->toBeFalse();

    $this->access->grant(actorFor($this->owner), $otherOwner, Role::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('key', 'cashier')->first(),
        ScopeType::Tenant, $this->tenant->id);
})->throws(AccessDenied::class);

it('signs out every token of a deactivated user', function () {
    $c = staff($this->owner, 'gone', 2);
    $c->createToken('x', ['pos']);

    $this->access->updateUser(actorFor($this->owner), $c, ['is_active' => false], 'Resigned');

    expect($c->tokens()->count())->toBe(0);
});
