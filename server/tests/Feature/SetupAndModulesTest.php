<?php

use App\Domain\Modules\ModuleService;
use App\Domain\Org\OrgService;
use App\Domain\Org\TenantBaseline;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Till;
use App\Models\Warehouse;
use App\Modules\ModuleRegistry;
use App\Security\AccessCatalogSync;
use App\Security\ScopeType;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->modules = app(ModuleService::class);
});

it('wizard creates branches with a warehouse, tills and per-branch modules', function () {
    $b = setUpBranches($this->owner, [
        ['code' => 'HQ', 'name' => 'Head Office', 'is_hq' => true, 'tills' => 1, 'modules' => ['retail_wholesale']],
        ['code' => 'A', 'name' => 'Osu Grocery', 'is_hq' => false, 'tills' => 2, 'modules' => ['grocery']],
        ['code' => 'B', 'name' => 'Food Court', 'is_hq' => false, 'tills' => 1, 'modules' => ['restaurant', 'grocery', 'manufacturing']],
        ['code' => 'C', 'name' => 'Pharmacy', 'is_hq' => false, 'tills' => 1, 'modules' => ['pharmacy']],
    ]);

    expect($this->tenant->fresh()->isSetUp())->toBeTrue()
        ->and(Warehouse::count())->toBe(4)
        ->and(Till::where('branch_id', $b['A']->id)->count())->toBe(2)
        ->and($this->modules->statuses($b['A']))->toBe(['core' => 'enabled', 'grocery' => 'enabled']) // released in Phase 9a
        ->and($this->modules->statuses($b['B']))->toMatchArray(['restaurant' => 'enabled', 'grocery' => 'enabled', 'manufacturing' => 'reserved']) // restaurant released in 9c
        ->and($this->modules->statuses($b['C']))->not->toHaveKey('grocery')
        ->and($this->modules->isEnabled($b['C'], 'pharmacy'))->toBeTrue() // released in Phase 9b
        ->and($this->modules->isEnabled($b['B'], 'manufacturing'))->toBeFalse(); // reserved ≠ working
});

it('wizard runs once and needs exactly one HQ', function () {
    expect(fn () => setUpBranches($this->owner, [['code' => 'A', 'name' => 'A', 'is_hq' => false, 'tills' => 1]]))
        ->toThrow(DomainException::class, 'exactly one branch');
    setUpBranches($this->owner);
    expect(fn () => setUpBranches($this->owner))->toThrow(DomainException::class, 'already been completed');
});

it('wizard is refused to people without setup rights', function () {
    $c = staff($this->owner, 'cashier1', 2);
    app(OrgService::class)->completeSetup(actorFor($c), ['deployment_mode' => 'cloud', 'branches' => []]);
})->throws(AccessDenied::class);

it('never enables an unreleased module, but reserves it', function () {
    $b = setUpBranches($this->owner);
    expect(fn () => $this->modules->setStatus(actorFor($this->owner), $b['HQ'], 'manufacturing', 'enabled'))
        ->toThrow(DomainException::class, 'not released');

    $row = $this->modules->setStatus(actorFor($this->owner), $b['HQ'], 'manufacturing', 'reserved', 'Planned workshop');
    expect($row->status)->toBe('reserved');
});

it('keeps Core installed', function () {
    $b = setUpBranches($this->owner);
    $this->modules->setStatus(actorFor($this->owner), $b['HQ'], 'core', 'disabled');
})->throws(DomainException::class, 'always installed');

it('scopes module management to the branch an admin holds', function () {
    $b = setUpBranches($this->owner);
    $it = staff($this->owner, 'itb', 4, 'it_admin', ScopeType::Branch, $b['BA']->id);

    $this->modules->setStatus(actorFor($it), $b['BA'], 'pharmacy', 'reserved');
    expect(fn () => $this->modules->setStatus(actorFor($it), $b['HQ'], 'pharmacy', 'reserved'))->toThrow(AccessDenied::class);
});

it('reads manifests and only seeds permissions of released modules', function () {
    $reg = app(ModuleRegistry::class);

    expect($reg->has('grocery'))->toBeTrue()
        ->and($reg->get('core')->isAvailable())->toBeTrue()
        ->and(collect($reg->permissions())->pluck('module')->unique()->sort()->values()->all())->toBe(['core', 'electronics', 'grocery', 'pharmacy', 'restaurant']) // planned modules (manufacturing…) add none yet
        ->and(Permission::count())->toBe(count($reg->permissions()));
});

it('re-seeding never re-adds a permission an admin removed from a role', function () {
    $cashier = Role::where('key', 'cashier')->first();
    $cashier->permissions()->detach('pos.access');

    app(DatabaseSeeder::class)->run(app(AccessCatalogSync::class), app(TenantBaseline::class));

    expect($cashier->fresh()->permissionKeys())->not->toContain('pos.access')
        ->and(Role::where('key', 'cashier')->count())->toBe(1);
});

it('validates branch codes used in document numbers', function () {
    setUpBranches($this->owner);
    app(OrgService::class)->createBranch(actorFor($this->owner), ['code' => 'bad code!', 'name' => 'X']);
})->throws(DomainException::class, 'Branch code');

it('creating a branch later enables Core on it', function () {
    setUpBranches($this->owner);
    $b = app(OrgService::class)->createBranch(actorFor($this->owner), ['code' => 'KSI', 'name' => 'Kumasi'], 2);

    expect($this->modules->isEnabled($b, 'core'))->toBeTrue()
        ->and(Branch::where('code', 'KSI')->first()->tills()->count())->toBe(2);
});
