<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Imports\ImportService;
use App\Domain\Support\DomainException;
use App\Models\Barcode;
use App\Models\Industry;
use App\Models\Product;
use App\Models\TaxGroup;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Support\IndustryContext;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner, [
        ['code' => 'HQ', 'name' => 'Head Office', 'is_hq' => true, 'tills' => 1, 'modules' => []],
        ['code' => 'P1', 'name' => 'Pharmacy', 'is_hq' => false, 'tills' => 1, 'modules' => ['pharmacy']],
        ['code' => 'G1', 'name' => 'Supermarket', 'is_hq' => false, 'tills' => 1, 'modules' => ['grocery']],
    ]);
    $this->pharmacy = Industry::where('module_key', 'pharmacy')->firstOrFail();
    $this->grocery = Industry::where('module_key', 'grocery')->firstOrFail();
    $this->make = fn (string $name, string $industry, string $barcode) => app(CatalogService::class)->saveProduct(actorFor($this->owner), new Product, [
        'name' => $name, 'industry_id' => $industry, 'base_uom_id' => Uom::where('code', 'PC')->value('id'),
        'tax_group_id' => TaxGroup::where('code', 'STD')->value('id'), 'track_stock' => true, 'is_active' => true, 'base_barcodes' => [$barcode]]);
});

it('lets each industry have its own product with the same barcode, but never twice in one industry or with a shared one', function () {
    $pharma = ($this->make)('Lactogen 1', $this->pharmacy->id, '7501058625915');
    $super = ($this->make)('Nestle Lactogen 400g', $this->grocery->id, '7501058625915');
    expect(Barcode::where('code', '7501058625915')->pluck('industry_id')->sort()->values()->all())->toBe(collect([$this->pharmacy->id, $this->grocery->id])->sort()->values()->all())
        ->and(fn () => ($this->make)('Lactogen again', $this->grocery->id, '7501058625915'))->toThrow(DomainException::class, 'already belongs to NESTLE LACTOGEN 400G')
        ->and(fn () => ($this->make)('Lactogen shared', IndustryContext::SHARED, '7501058625915'))->toThrow(DomainException::class, 'already belongs to');

    ($this->make)('Carrier bag', IndustryContext::SHARED, '6000000000017');
    expect(fn () => ($this->make)('Bag', $this->grocery->id, '6000000000017'))->toThrow(DomainException::class, 'already belongs to CARRIER BAG');
});

it('reads a barcode in the store\'s industry, and asks for the SKU at head office when it is ambiguous', function () {
    $pharma = ($this->make)('Lactogen 1', $this->pharmacy->id, '7501058625915');
    $super = ($this->make)('Nestle Lactogen 400g', $this->grocery->id, '7501058625915');
    $wh = fn ($code) => Warehouse::where('branch_id', $this->b[$code]->id)->first();

    expect(Barcode::scanned('7501058625915', $this->grocery->id)->product_id)->toBe($super->id)
        ->and(Barcode::scanned('7501058625915', $this->pharmacy->id)->product_id)->toBe($pharma->id)
        ->and(Barcode::scanned('7501058625915', null))->toBeNull();

    $done = app(ImportService::class)->run(actorFor($this->owner), 'opening_stock', barcodeCsv([['warehouse', 'barcode', 'qty', 'unit_cost'],
        [$wh('G1')->code, '7501058625915', '10', '70'], [$wh('P1')->code, '7501058625915', '4', '72'], [$wh('HQ')->code, '7501058625915', '1', '70']]), 'o.csv', ['skip_errors' => true], null, true);
    expect((string) $super->stockLevels()->where('warehouse_id', $wh('G1')->id)->value('on_hand'))->toBe('10.000')
        ->and((string) $pharma->stockLevels()->where('warehouse_id', $wh('P1')->id)->value('on_hand'))->toBe('4.000')
        ->and(collect($done->errors)->pluck('message')->implode(' '))->toContain('several industries: give the SKU');
});

it('moves a product\'s barcodes with it, and refuses when the code is taken in the new industry', function () {
    $pharma = ($this->make)('Lactogen 1', $this->pharmacy->id, '7501058625915');
    $other = ($this->make)('Olive oil', $this->pharmacy->id, '8410179200835');
    ($this->make)('Borges olive oil', $this->grocery->id, '8410179200835');

    app(CatalogService::class)->moveToIndustry(actorFor($this->owner), [$pharma], $this->grocery->id);
    expect(Barcode::where('product_id', $pharma->id)->value('industry_id'))->toBe($this->grocery->id)
        ->and(fn () => app(CatalogService::class)->moveToIndustry(actorFor($this->owner), [$other], $this->grocery->id))->toThrow(DomainException::class, 'already belongs to BORGES OLIVE OIL')
        ->and($other->fresh()->industry_id)->toBe($this->pharmacy->id);
});

function barcodeCsv(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'bc').'.csv';
    $h = fopen($path, 'w');
    foreach ($rows as $r) {
        fputcsv($h, $r);
    }
    fclose($h);

    return $path;
}
