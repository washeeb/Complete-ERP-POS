<?php

use App\Domain\Customers\CustomerService;
use App\Domain\Purchasing\SupplierService;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Customer;
use App\Models\Supplier;
use Filament\Facades\Filament;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    setUpBranches($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner));
});

it('finds products by name, SKU or barcode and shows the SKU', function () {
    $p = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', baseBarcode: '6001067000123');
    foreach (['milo', 'MILO', '6001067000123'] as $term) {
        $r = ProductResource::getGlobalSearchResults($term)->first();
        expect($r)->not->toBeNull()->and($r->title)->toBe('Milo 400g')->and($r->details)->toMatchArray(['SKU' => 'MILO'])
            ->and($r->url)->toContain((string) $p->id);
    }
});

it('searches customers, suppliers and every searchable list without errors', function () {
    app(CustomerService::class)->save(actorFor($this->owner), new Customer, ['name' => 'Ama Owusu', 'phone' => '0241112222']);
    app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['code' => 'ERNEST', 'name' => 'Ernest Chemists', 'phone' => '0302000000']);
    expect(CustomerResource::getGlobalSearchResults('0241112222')->first()?->title)->toBe('Ama Owusu')
        ->and(SupplierResource::getGlobalSearchResults('ernest')->first()?->details)->toMatchArray(['Code' => 'ERNEST']);

    $searchable = collect(Filament::getPanel('admin')->getResources())->filter(fn ($r) => $r::canGloballySearch());
    expect($searchable->count())->toBeGreaterThan(30);
    foreach ($searchable as $resource) {
        expect(fn () => $resource::getGlobalSearchResults('a'))->not->toThrow(Throwable::class);
    }
});
