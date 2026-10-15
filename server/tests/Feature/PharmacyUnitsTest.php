<?php

use App\Domain\Catalog\IndustryUnits;
use App\Domain\Imports\Importer;
use App\Models\Uom;

it('adds the pharmacy units when Pharmacy is switched on, once, keeping the company\'s own', function () {
    [$tenant, $owner] = installCompany();
    Uom::where('code', 'PK')->update(['name' => 'Packet']); // the company renamed it: still not added twice
    setUpBranches($owner, [['code' => 'PH', 'name' => 'Pharmacy', 'is_hq' => true, 'tills' => 1, 'modules' => ['pharmacy']]]);

    expect(Uom::whereIn('code', ['TAB', 'STRIP', 'AMP', 'VIAL', 'TUBE', 'BTL', 'PESS', 'SUPP', 'CONT'])->count())->toBe(9)
        ->and(Uom::where('code', 'PK')->count())->toBe(1)
        ->and(Uom::where('code', 'TAB')->value('name'))->toBe('Tablet');
    expect(app(IndustryUnits::class)->ensure($tenant->id, 'pharmacy'))->toBe(0); // nothing new the second time
});

it('leaves units alone for other industries', function () {
    [, $owner] = installCompany();
    setUpBranches($owner, [['code' => 'RT', 'name' => 'Retail', 'is_hq' => true, 'tills' => 1, 'modules' => ['grocery']]]);
    expect(Uom::where('code', 'TAB')->exists())->toBeFalse();
});

it('finds a unit in an import by code or name', function () {
    [, $owner] = installCompany();
    setUpBranches($owner, [['code' => 'PH', 'name' => 'Pharmacy', 'is_hq' => true, 'tills' => 1, 'modules' => ['pharmacy']]]);
    $id = fn ($code) => Uom::where('code', $code)->value('id');
    expect(Importer::unitId('TAB'))->toBe($id('TAB'))
        ->and(Importer::unitId('tablet'))->toBe($id('TAB'))
        ->and(Importer::unitId('Strips'))->toBe($id('STRIP'))
        ->and(Importer::unitId('Cont.'))->toBe($id('CONT'))
        ->and(Importer::unitId('Bottle'))->toBe($id('BTL'))
        ->and(Importer::unitId('xx'))->toBeNull();
});
