<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Catalog\DuplicateProducts;
use App\Domain\Inventory\InventoryService;
use App\Models\AuditEvent;
use App\Models\DataImport;
use App\Models\Product;
use App\Models\TaxGroup;
use App\Models\Uom;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/** A product created the way the old import did ("CSV import"). */
function importedCopy($t, string $name, ?string $barcode = null): Product
{
    return app(CatalogService::class)->saveProduct(actorFor($t->owner), new Product, ['name' => $name, 'base_uom_id' => Uom::where('code', 'PC')->value('id'),
        'tax_group_id' => TaxGroup::where('code', 'STD')->value('id'), 'base_barcodes' => $barcode ? [$barcode] : []], 'CSV import');
}

/** The end of an import run. */
function importRun($t): void
{
    DataImport::create(['id' => (string) Str::uuid7(), 'tenant_id' => $t->tenant->id, 'type' => 'products', 'file_name' => 'p.xlsx', 'stored_path' => 'x', 'status' => 'imported',
        'options' => [], 'rows_total' => 0, 'rows_ok' => 0, 'rows_error' => 0, 'summary' => [], 'errors' => [], 'user_id' => $t->owner->id, 'user_name' => 'Owner', 'completed_at' => now()]);
}

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    setUpBranches($this->owner);
});

it('keeps the first copies, moves later copies\' barcodes to them, removes unused copies and switches off used ones', function () {
    // Run 1: three products, two of them "D10" on purpose (two different products in the file).
    $this->travelTo(now()->subMinutes(30));
    $mopa = importedCopy($this, 'M-DOPAKANT 250ML');
    $d10a = importedCopy($this, 'D10');
    $d10b = importedCopy($this, 'D10');
    importRun($this);
    // Run 2: the same file again; this time a barcode went onto the copy.
    $this->travelTo(now()->addMinutes(10));
    $mopa2 = importedCopy($this, 'M-DOPAKANT  250ml', '6001234567893');
    $d10a2 = importedCopy($this, 'D10');
    $d10b2 = importedCopy($this, 'D10');
    importRun($this);
    // Run 3: once more; this copy already has stock, so it must stay (switched off).
    $this->travelTo(now()->addMinutes(10));
    $mopa3 = importedCopy($this, 'M-DOPAKANT 250ML');
    importRun($this);
    $this->travelBack();
    app(InventoryService::class)->adjust(actorFor($this->owner), Warehouse::first(), $mopa3, $mopa3->units->first(), '5', 'opening', '');

    $svc = app(DuplicateProducts::class);
    $plan = collect($svc->plan($this->tenant->id))->mapWithKeys(fn ($g) => [$g['keep']->sku => collect($g['copies'])->pluck('sku')->all()]);
    expect($plan->all())->toBe([$mopa->sku => [$mopa2->sku, $mopa3->sku], $d10a->sku => [$d10a2->sku], $d10b->sku => [$d10b2->sku]]);

    Artisan::call('erp:merge-duplicate-products'); // without --apply nothing changes
    expect(Product::count())->toBe(7);
    Artisan::call('erp:merge-duplicate-products', ['--apply' => true]);

    expect(Product::pluck('sku')->sort()->values()->all())->toBe(collect([$mopa->sku, $d10a->sku, $d10b->sku, $mopa3->sku])->sort()->values()->all())
        ->and($mopa->fresh()->barcodes()->pluck('code')->all())->toBe(['6001234567893'])
        ->and($mopa3->fresh()->is_active)->toBeFalse()
        ->and(AuditEvent::where('action', 'product.duplicates_merged')->count())->toBe(3);

    Artisan::call('erp:merge-duplicate-products', ['--apply' => true]); // again: nothing more to do
    expect(Product::count())->toBe(4);
});
