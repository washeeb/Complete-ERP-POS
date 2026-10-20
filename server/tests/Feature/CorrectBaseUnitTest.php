<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Imports\Importers\ProductsUpdateImporter;
use App\Domain\Inventory\InventoryService;
use App\Domain\Support\DomainException;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\Uom;
use App\Models\Warehouse;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->wh = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->diapers = makeProduct($this->owner, 'DIAP', 'Softcare Diapers M/S', retail: '45.00', baseBarcode: '6033000410122');
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->wh, $this->diapers, $this->diapers->units->first(), '30', 'opening', '', null, '30.00');
    $this->pk = Uom::firstOrCreate(['code' => 'PK'], ['name' => 'Pack', 'decimals' => 0]);
});

it('renames the base unit after stock has moved: quantities, prices and barcodes stay', function () {
    $base = $this->diapers->units->firstWhere('is_base', true);
    $p = app(CatalogService::class)->correctBaseUnit(actorFor($this->owner), $this->diapers, $this->pk->id, 'Sold in packs');
    expect($p->base_uom_id)->toBe($this->pk->id)->and($base->fresh()->uom_id)->toBe($this->pk->id)
        ->and((string) DB::table('stock_levels')->where('product_id', $p->id)->where('warehouse_id', $this->wh->id)->value('on_hand'))->toBe('30.000')
        ->and(DB::table('barcodes')->where('product_uom_id', $base->id)->value('code'))->toBe('6033000410122')
        ->and(DB::table('prices')->where('product_uom_id', $base->id)->exists())->toBeTrue()
        ->and(DB::table('audit_events')->where('action', 'product.base_unit_corrected')->count())->toBe(1);
    expect(fn () => app(CatalogService::class)->correctBaseUnit(actorFor($this->owner), $p->fresh(), $this->pk->id, 'again'))->toThrow(DomainException::class, 'already');
});

it('corrects the unit from the products file and from the product page', function () {
    app(ProductsUpdateImporter::class)->apply(actorFor($this->owner), ['sku' => 'DIAP', 'base_unit' => 'PK'], [], null);
    expect($this->diapers->fresh()->base_uom_id)->toBe($this->pk->id);

    $pc = Uom::where('code', 'PC')->first();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner));
    Livewire::test(EditProduct::class, ['record' => $this->diapers->id])
        ->callAction('changeUnit', ['mode' => 'relabel', 'uom_id' => $pc->id, 'reason' => 'Back to pieces'])->assertNotified('Unit changed');
    expect($this->diapers->fresh()->base_uom_id)->toBe($pc->id);
});
