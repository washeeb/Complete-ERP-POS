<?php

use App\Domain\Catalog\CatalogService;
use App\Filament\Resources\Brands\BrandResource;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\PriceLists\PriceListResource;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Resources\Shifts\ShiftResource;
use App\Filament\Resources\StockLevels\Pages\ListStockLevels;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\TaxGroups\Pages\ListTaxGroups;
use App\Filament\Resources\TaxGroups\TaxGroupResource;
use App\Filament\Resources\TaxRates\TaxRateResource;
use App\Filament\Resources\Uoms\UomResource;
use App\Models\AuditEvent;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\TaxGroup;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner));
});

it('shows every catalog, stock and sales screen to the owner', function () {
    $p = makeProduct($this->owner, 'TEA', 'Lipton tea', retail: '30.00', carton: ['12', '340.00']);

    foreach ([ProductResource::getUrl(), ProductResource::getUrl('create'), ProductResource::getUrl('edit', ['record' => $p]),
        CategoryResource::getUrl(), BrandResource::getUrl(), UomResource::getUrl(), TaxRateResource::getUrl(), TaxGroupResource::getUrl(),
        PriceListResource::getUrl(), StockLevelResource::getUrl(), StockMovementResource::getUrl(), SaleResource::getUrl(),
        ShiftResource::getUrl()] as $url) {
        $this->get($url)->assertOk();
    }
});

it('creates a product with a carton unit, barcodes and prices from the screen', function () {
    $pc = Uom::where('code', 'PC')->value('id');
    $ctn = Uom::where('code', 'CTN')->value('id');
    $retail = PriceList::where('code', 'RETAIL')->value('id');

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'PEAK MILK 160G', 'base_uom_id' => $pc, 'tax_group_id' => TaxGroup::where('code', 'STD')->value('id'),
            'base_barcodes' => ['8712100000001'],
            'units' => [['uom_id' => $ctn, 'factor' => '48', 'is_sellable' => true, 'is_purchasable' => true, 'barcodes' => ['8712100000048']]],
            'prices' => [
                ['price_list_id' => $retail, 'uom_id' => $pc, 'min_qty' => '1', 'amount' => '10.50'],
                ['price_list_id' => $retail, 'uom_id' => $ctn, 'min_qty' => '1', 'amount' => '480'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $p = Product::where('name', 'PEAK MILK 160G')->with('units', 'barcodes', 'prices')->first();
    expect($p)->not->toBeNull()
        ->and($p->sku)->toMatch('/^([A-Z0-9]+-\d{6}|1\d{5})$/')->not->toStartWith('SKU') // given by the system: an industry's series or a plain number
        ->and($p->units)->toHaveCount(2)
        ->and($p->barcodes->pluck('code')->sort()->values()->all())->toBe(['8712100000001', '8712100000048'])
        ->and($p->prices->pluck('amount_minor')->sort()->values()->all())->toBe([1050, 48000]);
});

it('round-trips a product through the edit screen without changing anything', function () {
    $p = makeProduct($this->owner, 'TEA2', 'Lipton tea', retail: '30.00', carton: ['12', '340.00'], wholesale: '28.00');
    $before = AuditEvent::count();

    Livewire::test(EditProduct::class, ['record' => $p->id])->call('save')->assertHasNoFormErrors();

    expect(AuditEvent::count())->toBe($before) // no-op saves never manufacture history
        ->and($p->fresh()->prices)->toHaveCount(4);
});

it('records opening stock from the stock screen', function () {
    $p = makeProduct($this->owner, 'TEA3', 'Lipton tea', retail: '30.00');
    $wh = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();

    Livewire::test(ListStockLevels::class)
        ->callAction('adjust', ['type' => 'opening', 'warehouse_id' => $wh->id, 'product_id' => $p->id,
            'product_uom_id' => $p->units->first()->id, 'qty' => '12'])
        ->assertNotified('Stock updated');

    expect((string) StockLevel::where('warehouse_id', $wh->id)->where('product_id', $p->id)->value('on_hand'))->toBe('12.000');
});

it('records an accountant check on a tax group and clears it when the rates change', function () {
    $std = TaxGroup::where('code', 'STD')->first();

    Livewire::test(ListTaxGroups::class)
        ->callTableAction('verify', $std, ['note' => 'Checked by A. Mensah, CA (GH), against GRA notice'])
        ->assertNotified('Accountant check recorded');
    expect($std->fresh()->isVerified())->toBeTrue();

    app(CatalogService::class)->saveTaxGroup(actorFor($this->owner), $std->fresh(), [], [$std->rates->first()->id]);
    expect($std->fresh()->isVerified())->toBeFalse();
});

it('keeps catalog and sales screens away from cashiers', function () {
    $cashier = staff($this->owner, 'cash', 2, 'teller', ScopeType::Branch, $this->branches['HQ']->id);
    $this->actingAs($cashier);
    $this->get(ProductResource::getUrl())->assertForbidden();
    $this->get(SaleResource::getUrl())->assertForbidden();
});

it('lets a warehouse manager edit products but not prices', function () {
    $wm = withMfa(staff($this->owner, 'wm', 4, 'warehouse_manager'));
    $this->actingAs($wm);
    $this->get(ProductResource::getUrl('create'))->assertOk()->assertDontSee('One row per price list');
    $this->get(PriceListResource::getUrl())->assertOk(); // can see lists…
    expect(PriceListResource::canCreate())->toBeFalse(); // …but not change them
});
