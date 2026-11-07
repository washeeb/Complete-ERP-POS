<?php

use App\Domain\Access\AccessService;
use App\Domain\Catalog\CatalogService;
use App\Domain\Catalog\SharedProducts;
use App\Domain\Inventory\InventoryService;
use App\Domain\Support\DomainException;
use App\Filament\Pages\CompanyWideAccess;
use App\Filament\Pages\SharedProductsPage;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Livewire\IndustrySwitcher;
use App\Models\Branch;
use App\Models\Industry;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\TaxGroup;
use App\Models\Uom;
use App\Security\ScopeType;
use App\Support\IndustryContext;
use App\Support\Settings;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner, [
        ['code' => 'HQ', 'name' => 'Head Office', 'is_hq' => true, 'tills' => 1, 'modules' => []],
        ['code' => 'P1', 'name' => 'Pharmacy Osu', 'is_hq' => false, 'tills' => 1, 'modules' => ['pharmacy']],
        ['code' => 'G1', 'name' => 'Grocery Spintex', 'is_hq' => false, 'tills' => 1, 'modules' => ['grocery']],
    ]);
    $this->pharmacy = Industry::where('module_key', 'pharmacy')->firstOrFail();
    $this->grocery = Industry::where('module_key', 'grocery')->firstOrFail();
    $this->clerk = staff($this->owner, 'stock', 3, 'stock_controller', ScopeType::Branch, $this->b['G1']->id);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function bareProduct(array $over = []): array
{
    return $over + ['name' => 'Rice 5kg', 'base_uom_id' => Uom::where('code', 'PC')->value('id'), 'tax_group_id' => TaxGroup::where('code', 'STD')->value('id'), 'track_stock' => true, 'is_active' => true];
}

function productIn(User|App\Models\User $owner, string $name, string $industry): Product
{
    return app(CatalogService::class)->saveProduct(actorFor($owner), new Product, bareProduct(['name' => $name, 'industry_id' => $industry]));
}

it('keeps someone with one industry in it: no switcher, and they are always in it', function () {
    $this->actingAs(withMfa($this->clerk), 'web');
    expect(IndustryContext::selected())->toBe($this->grocery->id)
        ->and(Erp::branchIds('catalog.view'))->toBe([$this->b['G1']->id]);
    Livewire::test(IndustrySwitcher::class)->assertDontSee('All industries');
});

it('shows the switcher to company-wide people and those covering several industries, not to high levels in one', function () {
    $this->actingAs(withMfa($this->owner), 'web');
    Livewire::test(IndustrySwitcher::class)->assertSee('All industries');

    $both = staff($this->owner, 'area', 4, 'branch_manager', ScopeType::Industry, $this->pharmacy->id);
    app(AccessService::class)->grant(actorFor($this->owner), $both, Role::where('key', 'branch_manager')->first(), ScopeType::Industry, $this->grocery->id);
    $this->actingAs(withMfa($both->fresh()), 'web');
    Livewire::test(IndustrySwitcher::class)->assertSee('All industries');

    $gm = staff($this->owner, 'gm', 5, 'general_manager', ScopeType::Branch, $this->b['G1']->id);
    $this->actingAs(withMfa($gm), 'web');
    Livewire::test(IndustrySwitcher::class)->assertDontSee('All industries');
});

it('never makes a new product shared by leaving the industry empty', function () {
    expect(fn () => app(CatalogService::class)->saveProduct(actorFor($this->owner), new Product, bareProduct()))
        ->toThrow(DomainException::class, 'Choose which industry');

    $mine = app(CatalogService::class)->saveProduct(actorFor($this->clerk), new Product, bareProduct());
    expect($mine->industry_id)->toBe($this->grocery->id)->and($mine->sku)->toStartWith('GROCER-');
});

it('keeps people to their own industry, and sharing to company-wide people', function () {
    $save = fn ($user, $industry) => app(CatalogService::class)->saveProduct(actorFor($user), new Product, bareProduct(['name' => 'Bag '.$industry, 'industry_id' => $industry]));

    expect(fn () => $save($this->clerk, $this->pharmacy->id))->toThrow(DomainException::class, 'your own industry')
        ->and(fn () => $save($this->clerk, IndustryContext::SHARED))->toThrow(DomainException::class, 'company-wide')
        ->and($save($this->owner, IndustryContext::SHARED)->industry_id)->toBeNull();

    app(Settings::class)->set(actorFor($this->owner), 'catalog.shared_products', 'never');
    expect(fn () => $save($this->owner, IndustryContext::SHARED))->toThrow(DomainException::class, 'switched off');
});

it('leaves existing shared products alone when they are edited, and refuses moving another industry\'s product', function () {
    $shared = app(CatalogService::class)->saveProduct(actorFor($this->owner), new Product, bareProduct(['name' => 'Carrier bag', 'industry_id' => IndustryContext::SHARED]));
    $pill = productIn($this->owner, 'Pill', $this->pharmacy->id);

    expect(app(CatalogService::class)->saveProduct(actorFor($this->clerk), $shared, ['name' => 'Carrier bag large'])->industry_id)->toBeNull()
        ->and(fn () => app(CatalogService::class)->moveToIndustry(actorFor($this->clerk), [$pill], $this->grocery->id))->toThrow(DomainException::class, 'your own industry')
        ->and(fn () => app(CatalogService::class)->moveToIndustry(actorFor($this->clerk), [$shared->fresh()], $this->grocery->id))->toThrow(DomainException::class, 'your own industry');
});

it('requires the industry on the product form and offers only the person\'s own', function () {
    $this->actingAs(withMfa($this->owner), 'web');
    Livewire::test(CreateProduct::class)->fillForm(['name' => 'No industry', 'industry_id' => null, 'base_uom_id' => Uom::where('code', 'PC')->value('id'), 'tax_group_id' => TaxGroup::where('code', 'STD')->value('id')])
        ->call('create')->assertHasFormErrors(['industry_id' => 'required']);

    $this->actingAs(withMfa($this->clerk), 'web');
    expect(IndustryContext::choices($this->clerk))->toBe([$this->grocery->id => $this->grocery->name]);
    Livewire::test(CreateProduct::class)->assertFormSet(['industry_id' => $this->grocery->id]);
});

it('hides another industry\'s products from lists, links and pickers', function () {
    $pill = productIn($this->owner, 'Pill', $this->pharmacy->id);
    $rice = productIn($this->owner, 'Rice', $this->grocery->id);
    $this->actingAs(withMfa($this->clerk), 'web');

    Livewire::test(ListProducts::class)->assertCanSeeTableRecords([$rice])->assertCanNotSeeTableRecords([$pill]);
    $this->get(EditProduct::getUrl(['record' => $pill]))->assertNotFound();
    expect(Fields::productUnit()->getSearchResults('PILL'))->toBe([])
        ->and(Fields::productUnit()->getSearchResults('RICE'))->toHaveCount(1);
});

it('suggests an industry from stock and sales, moves one at a time or all, and never by itself', function () {
    $save = fn ($name) => app(CatalogService::class)->saveProduct(actorFor($this->owner), new Product, bareProduct(['name' => $name, 'industry_id' => IndustryContext::SHARED]));
    $here = $save('Stocked');
    $there = $save('Not anywhere');
    $wh = $this->b['P1']->warehouses()->first();
    app(InventoryService::class)->adjust(actorFor($this->owner), $wh, $here, ProductUom::where('product_id', $here->id)->where('is_base', true)->first(), '5', 'opening', '', 'test', '1.00');

    $service = app(SharedProducts::class);
    $used = $service->whereUsed($this->tenant->id);
    expect(SharedProducts::suggestion($used[$here->id]))->toBe($this->pharmacy->id)
        ->and($used)->not->toHaveKey($there->id)
        ->and($here->fresh()->industry_id)->toBeNull();

    $this->actingAs(withMfa($this->owner), 'web');
    Livewire::test(SharedProductsPage::class)->assertCanSeeTableRecords([$here, $there])
        ->selectTableRecords([$here->id, $there->id])
        ->callAction(TestAction::make('moveSuggested')->table()->bulk());
    expect($here->fresh()->industry_id)->toBe($this->pharmacy->id)->and($there->fresh()->industry_id)->toBeNull();

    Livewire::test(SharedProductsPage::class)->callAction('moveAll', ['industry' => $this->grocery->id]);
    expect($there->fresh()->industry_id)->toBe($this->grocery->id)->and(Product::whereNull('industry_id')->count())->toBe(0);
});

it('limits company-wide access by role, and the owner can tighten it', function () {
    AccessService::$limitCompanyWide = true;
    $grant = fn (string $role, string $username, int $level) => staff($this->owner, $username, $level, $role);

    expect(fn () => $grant('picker_packer', 'pick', 2))->toThrow(DomainException::class, "can't have company-wide access")
        ->and($grant('accountant', 'acct', 4)->id)->not->toBeNull()
        ->and($grant('auditor', 'audit', 1)->id)->not->toBeNull();

    app(Settings::class)->set(actorFor($this->owner), 'access.company_wide', 'owner');
    expect(fn () => $grant('general_manager', 'gm', 5))->toThrow(DomainException::class, "can't have company-wide access");
});

it('lists people who have company-wide access but should not, and gives them a branch', function () {
    $old = staff($this->owner, 'pick', 2, 'picker_packer');
    $grant = RoleAssignment::where('user_id', $old->id)->firstOrFail();
    expect(CompanyWideAccess::getNavigationBadge())->toBe('1');

    $this->actingAs(withMfa($this->owner), 'web');
    Livewire::test(CompanyWideAccess::class)->assertCanSeeTableRecords([$grant])
        ->callAction(TestAction::make('narrow')->table($grant), ['scope_type' => 'branch', 'scope_id' => $this->b['G1']->id]);

    $now = RoleAssignment::where('user_id', $old->id)->active()->get();
    expect($now)->toHaveCount(1)->and($now[0]->scope_type)->toBe(ScopeType::Branch)->and($now[0]->scope_id)->toBe($this->b['G1']->id)
        ->and($now[0]->role_id)->toBe($grant->role_id)->and(CompanyWideAccess::getNavigationBadge())->toBeNull();
});

it('asks staff with several branches of one industry nothing, and head office chooses', function () {
    $branch = Branch::where('code', 'HQ')->first();
    $hq = staff($this->owner, 'buyer', 3, 'stock_controller', ScopeType::Branch, $branch->id);
    expect(array_keys(IndustryContext::choices($hq)))->toContain($this->pharmacy->id, $this->grocery->id)->not->toContain(IndustryContext::SHARED);
});
