<?php

use App\Domain\Access\AccessService;
use App\Domain\Org\OrgService;
use App\Domain\Org\TenantInstaller;
use App\Domain\Support\Actor;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Security\ScopeType;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/** Fresh company + owner (L6, Owner role company-wide). */
function installCompany(string $name = 'Demo Ltd', string $email = 'owner@demo.test'): array
{
    [$tenant, $owner] = app(TenantInstaller::class)->install($name, [
        'name' => 'Ama Owner', 'username' => 'owner'.substr(md5($email), 0, 4), 'email' => $email, 'password' => 'correct-horse-battery',
    ]);
    app(TenantContext::class)->set($tenant->id);

    return [$tenant, $owner];
}

function actorFor(User $user): Actor
{
    return Actor::user($user->fresh());
}

/** Owner completes the wizard with the given branches; returns branches keyed by code. */
function setUpBranches(User $owner, ?array $branches = null): array
{
    $branches ??= [
        ['code' => 'HQ', 'name' => 'Head Office', 'is_hq' => true, 'tills' => 1, 'modules' => ['retail_wholesale']],
        ['code' => 'BA', 'name' => 'Branch A', 'is_hq' => false, 'tills' => 2, 'modules' => ['grocery']],
    ];
    app(OrgService::class)->completeSetup(actorFor($owner), [
        'deployment_mode' => 'cloud',
        'company' => ['name' => 'Demo Ltd'],
        'branches' => $branches,
    ]);

    return Branch::all()->keyBy('code')->all();
}

/** Create a user via the owner, optionally with a role at a scope. */
function staff(User $owner, string $username, int $level, ?string $roleKey = null, ?ScopeType $scope = null, ?string $scopeId = null, array $limits = []): User
{
    $svc = app(AccessService::class);
    $user = $svc->createUser(actorFor($owner), [
        'name' => ucfirst($username), 'username' => $username, 'email' => "{$username}@demo.test",
        'password' => 'correct-horse-battery', 'security_level' => $level,
    ]);
    if ($roleKey) {
        $role = Role::where('key', $roleKey)->firstOrFail();
        $scope ??= ScopeType::Tenant;
        $svc->grant(actorFor($owner), $user, $role, $scope, $scope === ScopeType::Tenant ? $owner->tenant_id : $scopeId, $limits);
    }

    return $user->fresh();
}
