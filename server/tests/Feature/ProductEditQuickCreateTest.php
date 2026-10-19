<?php

use App\Domain\Catalog\CatalogService;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Uom;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    setUpBranches($this->owner);
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00'], baseBarcode: '6001067000123');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner));
});

it('saves an edited product with its barcodes without calling them duplicates or making a new product', function () {
    $count = Product::count();
    Livewire::test(EditProduct::class, ['record' => $this->milo->id])->fillForm(['name' => 'Milo 400g tin'])->call('save')->assertHasNoFormErrors();
    expect(Product::count())->toBe($count)->and($this->milo->fresh()->name)->toBe('Milo 400g tin')
        ->and(DB::table('barcodes')->where('code', '6001067000123')->count())->toBe(1);
});

it('changes the base unit before any stock: barcodes and prices follow, one base unit', function () {
    $pk = Uom::firstOrCreate(['code' => 'PK'], ['name' => 'Pack', 'decimals' => 0]);
    Livewire::test(EditProduct::class, ['record' => $this->milo->id])->fillForm(['base_uom_id' => $pk->id])->call('save')->assertHasNoFormErrors();
    $p = $this->milo->fresh(['units']);
    $base = $p->units->where('is_base', true);
    expect($base)->toHaveCount(1)->and($base->first()->uom_id)->toBe($pk->id)
        ->and(DB::table('barcodes')->where('code', '6001067000123')->value('product_uom_id'))->toBe($base->first()->id)
        ->and(DB::table('prices')->where('product_uom_id', $base->first()->id)->exists())->toBeTrue();

    // A file that only changes the base unit keeps the carton and its barcode.
    app(CatalogService::class)->saveProduct(actorFor($this->owner), $p, ['base_uom_id' => Uom::where('code', 'PC')->value('id')]);
    expect($p->fresh(['units'])->units->where('is_sellable', true))->toHaveCount(2);
});

it('adds a missing category, brand, unit or supplier from the form it is needed on', function () {
    Livewire::test(CreateProduct::class)
        ->callFormComponentAction('category_id', 'createOption', ['name' => 'Herbal'])
        ->callFormComponentAction('brand_id', 'createOption', ['name' => 'Ernest Chemists'])
        ->callFormComponentAction('base_uom_id', 'createOption', ['code' => 'sach', 'name' => 'Sachet', 'decimals' => 0])
        ->assertFormSet(['category_id' => Category::where('name', 'Herbal')->value('id'), 'brand_id' => Brand::where('name', 'Ernest Chemists')->value('id'),
            'base_uom_id' => Uom::where('code', 'SACH')->value('id')]);
    expect(DB::table('audit_events')->whereIn('action', ['category.created', 'brand.created', 'uom.created'])->count())->toBe(3);

    Livewire::test(CreatePurchaseOrder::class)->callFormComponentAction('supplier_id', 'createOption', ['code' => 'kama', 'name' => 'Kama Industries'])
        ->assertFormSet(['supplier_id' => Supplier::where('code', 'KAMA')->value('id')]);
});
