<?php

use App\Domain\Catalog\CatalogService;
use App\Models\Industry;
use App\Models\Product;
use App\Models\TaxGroup;
use App\Models\Uom;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    setUpBranches($this->owner, [['code' => 'PH', 'name' => 'Pharmacy', 'is_hq' => true, 'tills' => 1, 'modules' => ['pharmacy']]]);
    $this->pharmacy = Industry::where('module_key', 'pharmacy')->firstOrFail();
    $this->make = fn (string $name, ?string $industry = null, ?string $sku = null) => app(CatalogService::class)->saveProduct(actorFor($this->owner), new Product,
        array_filter(['name' => $name, 'sku' => $sku, 'industry_id' => $industry, 'base_uom_id' => Uom::where('code', 'PC')->value('id'),
            'tax_group_id' => TaxGroup::where('code', 'STD')->value('id'), 'base_barcodes' => []], fn ($v) => $v !== null));
});

it('numbers shared products without a prefix, and an industry\'s with its code', function () {
    expect(($this->make)('Carrier bag')->sku)->toBe('100001')
        ->and(($this->make)('Water 500ml')->sku)->toBe('100002')
        ->and(($this->make)('Paracetamol', $this->pharmacy->id)->sku)->toBe($this->pharmacy->code.'-000001');
});

it('gives a moved product the new industry\'s SKU, but keeps a code typed in from an old system', function () {
    $given = ($this->make)('Vitamin C');
    $typed = ($this->make)('Zinc', null, 'OLD-77');
    app(CatalogService::class)->moveToIndustry(actorFor($this->owner), [$given, $typed], $this->pharmacy->id);
    expect($given->fresh()->sku)->toBe($this->pharmacy->code.'-000001')->and($typed->fresh()->sku)->toBe('OLD-77');
});

it('renames SKU-000123 codes once: plain numbers when shared, the industry\'s series otherwise', function () {
    $shared = ($this->make)('Old shared');
    $pharm = ($this->make)('Old pharmacy', $this->pharmacy->id);
    DB::table('products')->where('id', $shared->id)->update(['sku' => 'SKU-000005']);
    DB::table('products')->where('id', $pharm->id)->update(['sku' => 'SKU-000006']);
    DB::table('document_sequences')->where('doc_type', 'SHARED')->delete();
    DB::table('document_sequences')->insert(['tenant_id' => $this->tenant->id, 'node' => 'catalog', 'doc_type' => 'SKU', 'last_value' => 6]);

    (require database_path('migrations/2026_11_08_100001_drop_sku_prefix.php'))->up();

    expect($shared->fresh()->sku)->toBe('100005')->and($pharm->fresh()->sku)->toBe($this->pharmacy->code.'-000002')
        ->and(Product::where('sku', 'like', 'SKU-%')->exists())->toBeFalse()
        ->and(($this->make)('New shared')->sku)->toBe('100007'); // the shared series carries on after the old one
});
