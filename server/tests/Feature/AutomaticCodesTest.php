<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Hr\HrService;
use App\Domain\Org\OrgService;
use App\Models\Department;
use App\Models\PriceList;
use App\Models\Till;
use App\Models\Warehouse;
use App\Support\Codes;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->a = actorFor($this->owner);
});

it('makes codes from names, never a taken one, and numbers series', function () {
    expect(Codes::fromName('Pharmacy Osu', [], 6))->toBe('PHAOSU')
        ->and(Codes::fromName('Pharmacy Osu', ['PHAOSU'], 6))->toBe('PHAOS2')
        ->and(Codes::fromName('Bakery', [], 6))->toBe('BAKERY')
        ->and(Codes::fromName('Kitchen', [], 10))->toBe('KITCHEN')
        ->and(Codes::fromName('Staff price', [], 20, '_'))->toBe('STAFF_PRICE')
        ->and(Codes::fromName('Staff price', ['STAFF_PRICE'], 20, '_'))->toBe('STAFF_PRICE_2')
        ->and(Codes::fromName('   ', [], 6, fallback: 'BR'))->toBe('BR')
        ->and(Codes::numbered('T', ['T01', 'T02', 'X9']))->toBe('T03')
        ->and(Codes::numbered('V', []))->toBe('V01');
});

it('suggests the code after the highest for codes people type', function () {
    expect(Codes::after(['A-01-02', 'A-01-10', 'A-01-09']))->toBe('A-01-11')
        ->and(Codes::after(['1010', '1020']))->toBe('1021')
        ->and(Codes::after(['BAR']))->toBeNull();
});

it('gives a new branch, its stores and tills their codes, and keeps them', function () {
    $org = app(OrgService::class);
    $branch = $org->createBranch($this->a, ['name' => 'Grocery Spintex'], 2);
    expect($branch->code)->toBe('GROSPI')
        ->and(Warehouse::where('branch_id', $branch->id)->pluck('code')->all())->toBe(['GROSPI-W1'])
        ->and(Till::where('branch_id', $branch->id)->orderBy('code')->pluck('code')->all())->toBe(['T01', 'T02'])
        ->and($org->createBranch($this->a, ['name' => 'Grocery Spintex'], 0)->code)->toBe('GROSP2');

    $store = $org->saveWarehouse($this->a, new Warehouse, ['branch_id' => $branch->id, 'name' => 'Back store', 'is_active' => true]);
    $till = $org->saveTill($this->a, new Till, ['branch_id' => $branch->id, 'warehouse_id' => $store->id, 'name' => 'Till 3', 'is_active' => true]);
    expect($store->code)->toBe('GROSPI-W2')->and($till->code)->toBe('T03')
        ->and($org->saveTill($this->a, $till, ['name' => 'Express', 'code' => 'ZZ'])->code)->toBe('T03');
});

it('gives departments and price lists their codes from the name', function () {
    $hr = app(HrService::class);
    $d = $hr->saveDepartment($this->a, new Department, '', 'Front Office');
    expect($d->code)->toBe('FROOFF')->and($hr->saveDepartment($this->a, $d, 'NEW', 'Front desk')->code)->toBe('FROOFF');

    $list = app(CatalogService::class)->savePriceList($this->a, new PriceList, ['name' => 'Staff price', 'prices_include_tax' => true, 'is_default' => false, 'is_active' => true]);
    expect($list->code)->toBe('STAFF_PRICE');
});
