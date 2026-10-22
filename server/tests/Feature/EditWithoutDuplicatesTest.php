<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Customers\CustomerService;
use App\Domain\Hr\HrService;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\DomainException;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Support\QuickCreate;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Supplier;
use App\Models\Uom;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    setUpBranches($this->owner);
    $this->a = actorFor($this->owner);
});

it('edits categories, brands, units, suppliers, customers and departments in place, never as new records', function () {
    $catalog = app(CatalogService::class);
    $cat = $catalog->saveSimple($this->a, new Category, ['name' => 'HERBAL', 'is_active' => true]);
    $brand = $catalog->saveSimple($this->a, new Brand, ['name' => 'Ernest']);
    $uom = $catalog->saveSimple($this->a, new Uom, ['code' => 'sach', 'name' => 'Sachet', 'decimals' => 0]);
    $sup = app(SupplierService::class)->save($this->a, new Supplier, ['code' => 'KAMA', 'name' => 'Kama', 'phone' => '0302000000']);
    $cus = app(CustomerService::class)->save($this->a, new Customer, ['name' => 'Ama', 'phone' => '0241112222']);
    $dep = app(HrService::class)->saveDepartment($this->a, new Department, 'DISP', 'Dispensary');
    $counts = fn () => [Category::count(), Brand::count(), Uom::count(), Supplier::count(), Customer::count(), Department::count()];
    $before = $counts();

    $catalog->saveSimple($this->a, $cat, ['name' => 'HERBAL MEDICINES']);
    $catalog->saveSimple($this->a, $brand, ['name' => 'ERNEST CHEMISTS']);
    $catalog->saveSimple($this->a, $uom, ['name' => 'Sachet (single)']);
    app(SupplierService::class)->save($this->a, $sup, ['name' => 'Kama Industries', 'phone' => '0302000000']);
    app(CustomerService::class)->save($this->a, $cus, ['name' => 'AMA OWUSU', 'phone' => '0241112222']);
    app(HrService::class)->saveDepartment($this->a, $dep, 'DISP', 'Dispensary and counter');
    expect($counts())->toBe($before)->and($cat->fresh()->name)->toBe('HERBAL MEDICINES')->and($cus->fresh()->name)->toBe('AMA OWUSU')
        ->and($sup->fresh()->name)->toBe('KAMA INDUSTRIES')->and($dep->fresh()->name)->toBe('Dispensary and counter');

    // A second one with the same name or code is refused, in any letter case.
    expect(fn () => $catalog->saveSimple($this->a, new Category, ['name' => 'HERBAL MEDICINES']))->toThrow(DomainException::class, 'already exists')
        ->and(fn () => $catalog->saveSimple($this->a, new Brand, ['name' => 'ernest chemists']))->toThrow(DomainException::class, 'already exists')
        ->and(fn () => $catalog->saveSimple($this->a, new Uom, ['code' => 'SACH', 'name' => 'x']))->toThrow(DomainException::class, 'already exists')
        ->and(fn () => app(SupplierService::class)->save($this->a, new Supplier, ['code' => 'kama', 'name' => 'Other']))->toThrow(DomainException::class, 'already used')
        ->and(fn () => app(CustomerService::class)->save($this->a, new Customer, ['name' => 'Someone', 'phone' => '024 111 2222']))->toThrow(DomainException::class, 'already has the phone');
});

it('picks the existing record when the "+" is used for something already there', function () {
    app(CatalogService::class)->saveSimple($this->a, new Category, ['name' => 'HERBAL', 'is_active' => true]);
    app(SupplierService::class)->save($this->a, new Supplier, ['code' => 'KAMA', 'name' => 'Kama Industries']);
    expect(QuickCreate::existing('category', ['name' => ' herbal ']))->not->toBeNull()
        ->and(QuickCreate::existing('supplier', ['code' => 'NEW', 'name' => 'KAMA INDUSTRIES']))->not->toBeNull()
        ->and(QuickCreate::existing('brand', ['name' => 'Nobody']))->toBeNull();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner));
    Livewire::test(CreateProduct::class)->callFormComponentAction('category_id', 'createOption', ['name' => 'HERBAL'])
        ->assertNotified('HERBAL already exists — selected')->assertFormSet(['category_id' => Category::where('name', 'HERBAL')->value('id')]);
    expect(Category::count())->toBe(1);
});
