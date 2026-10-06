<?php

use App\Domain\Access\AccessService;
use App\Domain\Catalog\CatalogService;
use App\Domain\Modules\ModuleService;
use App\Domain\Org\OrgService;
use App\Domain\Org\TenantInstaller;
use App\Domain\Support\Actor;
use App\Models\Branch;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Role;
use App\Models\TaxGroup;
use App\Models\Uom;
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

/**
 * Product sold in pieces (STD tax) with a RETAIL price, optional carton unit [factor, retail carton price]
 * and optional WHOLESALE piece price. Barcodes are derived from the SKU unless given.
 */
function makeProduct(User $owner, string $sku, string $name, string $retail, ?array $carton = null, ?string $wholesale = null, ?string $baseBarcode = null, string $tax = 'STD'): Product
{
    $pc = Uom::where('code', 'PC')->first();
    $ctn = Uom::where('code', 'CTN')->first();
    $retailList = PriceList::where('code', 'RETAIL')->first();
    $wholesaleList = PriceList::where('code', 'WHOLESALE')->first();
    $code = $baseBarcode ?? '6001067'.str_pad((string) (crc32($sku) % 100000), 5, '0', STR_PAD_LEFT).'1';
    $prices = [['price_list_id' => $retailList->id, 'uom_id' => $pc->id, 'min_qty' => '1', 'amount' => $retail]];
    $units = [];
    if ($carton) {
        $units[] = ['uom_id' => $ctn->id, 'factor' => $carton[0], 'barcodes' => [substr($code, 0, -1).'8']];
        $prices[] = ['price_list_id' => $retailList->id, 'uom_id' => $ctn->id, 'min_qty' => '1', 'amount' => $carton[1]];
    }
    if ($wholesale) {
        $prices[] = ['price_list_id' => $wholesaleList->id, 'uom_id' => $pc->id, 'min_qty' => '1', 'amount' => $wholesale];
        if ($carton) {
            $prices[] = ['price_list_id' => $wholesaleList->id, 'uom_id' => $ctn->id, 'min_qty' => '1', 'amount' => $carton[1]];
        }
    }

    return app(CatalogService::class)->saveProduct(actorFor($owner), new Product, [
        'sku' => $sku, 'name' => $name, 'base_uom_id' => $pc->id, 'tax_group_id' => TaxGroup::where('code', $tax)->value('id'),
        'track_stock' => true, 'is_active' => true, 'base_barcodes' => [$code], 'units' => $units, 'prices' => $prices,
    ]);
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

/** Pretend the person already set up their authenticator, so MFA doesn't block the screen under test. */
function withMfa(User $user): User
{
    $user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->saveQuietly();

    return $user->fresh();
}

function enableModule(User $owner, Branch $branch, string $key): void
{
    app(ModuleService::class)->setStatus(actorFor($owner), $branch, $key, 'enabled');
}
