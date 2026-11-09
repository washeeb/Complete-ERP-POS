<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Customers\CustomerService;
use App\Domain\Imports\ImportService;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\DomainException;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Industry;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Till;
use App\Security\ScopeType;
use App\Support\IndustryContext;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/** Owner 2026-11-09: the supermarket's categories and users showed in the pharmacy, and the other way round. */
beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner, [
        ['code' => 'HQ', 'name' => 'Head Office', 'is_hq' => true, 'tills' => 1, 'modules' => []],
        ['code' => 'P1', 'name' => 'Pharmacy Osu', 'is_hq' => false, 'tills' => 1, 'modules' => ['pharmacy']],
        ['code' => 'G1', 'name' => 'Supermarket Bogre', 'is_hq' => false, 'tills' => 1, 'modules' => ['grocery']],
    ]);
    $this->pharmacy = Industry::where('module_key', 'pharmacy')->firstOrFail();
    $this->grocery = Industry::where('module_key', 'grocery')->firstOrFail();
    $this->pharmacyClerk = staff($this->owner, 'phstock', 3, 'stock_controller', ScopeType::Branch, $this->b['P1']->id);
    $this->groceryClerk = staff($this->owner, 'smstock', 3, 'stock_controller', ScopeType::Branch, $this->b['G1']->id);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('keeps each industry\'s categories to itself, with shared ones seen by both', function () {
    $this->actingAs(withMfa($this->groceryClerk), 'web');
    $snacks = app(CatalogService::class)->saveSimple(actorFor($this->groceryClerk), new Category, ['name' => 'Snacks']);
    expect($snacks->industry_id)->toBe($this->grocery->id);

    $this->actingAs(withMfa($this->owner), 'web');
    $bags = app(CatalogService::class)->saveSimple(actorFor($this->owner), new Category, ['name' => 'Bags', 'industry_id' => IndustryContext::SHARED]);
    // The same name may exist in each industry.
    app(CatalogService::class)->saveSimple(actorFor($this->owner), new Category, ['name' => 'Snacks', 'industry_id' => $this->pharmacy->id]);

    $this->actingAs(withMfa($this->pharmacyClerk), 'web');
    expect(Category::pluck('name')->sort()->values()->all())->toBe(['BAGS', 'SNACKS'])
        ->and(Category::whereKey($snacks->id)->exists())->toBeFalse();
    Livewire::test(ListCategories::class)->assertCanNotSeeTableRecords([$snacks])->assertCanSeeTableRecords([$bags]);
});

it('gives each industry its own customers, also at the till', function () {
    $this->actingAs(withMfa($this->groceryClerk), 'web');
    $ama = app(CustomerService::class)->save(actorFor($this->owner), new Customer, ['name' => 'Ama', 'phone' => '0241234567']);
    expect($ama->industry_id)->toBe($this->grocery->id);

    // The same person may be a customer of the pharmacy too.
    $this->actingAs(withMfa($this->owner), 'web');
    $ama2 = app(CustomerService::class)->save(actorFor($this->owner), new Customer, ['name' => 'Ama', 'phone' => '0241234567', 'industry_id' => $this->pharmacy->id]);
    expect($ama2->id)->not->toBe($ama->id);
    expect(fn () => app(CustomerService::class)->save(actorFor($this->owner), new Customer, ['name' => 'Yaw', 'industry_id' => IndustryContext::SHARED]))
        ->toThrow(DomainException::class, 'industry it belongs to');

    $this->actingAs(withMfa($this->pharmacyClerk), 'web');
    expect(Customer::pluck('id')->all())->toBe([$ama2->id]);

    // A pharmacy till sees only pharmacy customers.
    $device = Device::forceCreate(['tenant_id' => $this->tenant->id, 'branch_id' => $this->b['P1']->id, 'till_id' => Till::where('branch_id', $this->b['P1']->id)->value('id'),
        'name' => 'Till', 'kind' => 'pos', 'status' => 'active', 'created_by' => $this->owner->id]);
    request()->attributes->set('device', $device);
    expect(Customer::pluck('id')->all())->toBe([$ama2->id]);
    request()->attributes->remove('device');
});

it('lets a supplier be shared on purpose, and keeps codes unique across industries', function () {
    $this->actingAs(withMfa($this->owner), 'web');
    $svc = app(SupplierService::class);
    $mine = $svc->save(actorFor($this->owner), new Supplier, ['name' => 'Kasapreko', 'industry_id' => $this->grocery->id, 'code' => 'KAS']);
    $both = $svc->save(actorFor($this->owner), new Supplier, ['name' => 'Ernest Chemists', 'industry_id' => IndustryContext::SHARED]);
    expect($both->industry_id)->toBeNull();
    expect(fn () => $svc->save(actorFor($this->owner), new Supplier, ['name' => 'Other', 'industry_id' => $this->pharmacy->id, 'code' => 'KAS']))
        ->toThrow(DomainException::class, 'already used');

    $this->actingAs(withMfa($this->pharmacyClerk), 'web');
    expect(Supplier::pluck('id')->all())->toBe([$both->id]);
});

it('shows an industry only its own staff, and company-wide people only under All industries', function () {
    // The users list needs company-wide user admin; such a person narrows it with the industry switcher.
    $this->actingAs(withMfa($this->owner), 'web');
    Livewire::test(ListUsers::class)->assertCanSeeTableRecords([$this->pharmacyClerk, $this->groceryClerk, $this->owner]);
    IndustryContext::set($this->grocery->id);
    Livewire::test(ListUsers::class)->assertCanSeeTableRecords([$this->groceryClerk])->assertCanNotSeeTableRecords([$this->pharmacyClerk, $this->owner]);
});

it('creates missing categories in the imported products\' own industry', function () {
    $this->actingAs(withMfa($this->owner), 'web');
    app(CatalogService::class)->saveSimple(actorFor($this->owner), new Category, ['name' => 'Drinks', 'industry_id' => $this->pharmacy->id]);
    $file = UploadedFile::fake()->createWithContent('p.csv', "name,category,price_retail\nMalt 330ml,Drinks,8\n");
    app(ImportService::class)->run(actorFor($this->owner), 'products', $file->getRealPath(), 'p.csv', ['industry' => $this->grocery->id, 'create_missing' => true], commit: true);
    $malt = Product::where('name', 'MALT 330ML')->firstOrFail();
    expect($malt->category->industry_id)->toBe($this->grocery->id)
        ->and(Category::withoutIndustryScope()->where('name', 'DRINKS')->count())->toBe(2);
});
