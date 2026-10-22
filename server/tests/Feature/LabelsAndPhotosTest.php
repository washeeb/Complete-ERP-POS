<?php

use App\Domain\Catalog\Labels;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockAdjustmentService;
use App\Domain\Support\DomainException;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\StockAdjustment;
use App\Models\Warehouse;
use App\Support\Ean13;
use App\Support\Settings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->a = actorFor($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', baseBarcode: '6001234500012');
    $this->gari = makeProduct($this->owner, 'GARI', 'Gari 1kg', retail: '18.00');
    $this->rice = makeProduct($this->owner, 'RICE', 'Rice 5kg', retail: '145.00');
    DB::table('barcodes')->whereIn('product_id', [$this->gari->id, $this->rice->id])->delete(); // no barcode yet
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('gives products without a barcode an in-store EAN-13 and prints shelf labels on an A4 sheet', function () {
    expect(app(Labels::class)->giveBarcodes($this->a, [$this->milo->id, $this->gari->id, $this->rice->id]))->toBe(2);
    $gari = $this->gari->fresh('units.barcodes')->units->first()->barcodes->first()->code;
    $rice = $this->rice->fresh('units.barcodes')->units->first()->barcodes->first()->code;
    expect($gari)->toStartWith('20')->and(Ean13::valid($gari))->toBeTrue()->and($rice)->toBe(Ean13::complete('20'.str_pad((string) ((int) substr($gari, 2, 10) + 1), 10, '0', STR_PAD_LEFT)))
        ->and($this->milo->fresh('units.barcodes')->units->first()->barcodes->pluck('code')->all())->toBe(['6001234500012']); // own barcode kept

    $url = app(Labels::class)->prepare($this->a, [$this->milo->id, $this->gari->id], ['kind' => 'shelf', 'layout' => 'a4_24', 'price_list_id' => null, 'copies' => 2, 'skip' => 3]);
    $this->actingAs(withMfa($this->owner), 'web')->get($url)->assertOk()->assertSee('MILO 400G')->assertSee('GH₵ 15.00')->assertSee('<svg', false)
        ->assertSee('4 label(s)');
    $this->actingAs(withMfa(staff($this->owner, 'clerk', 2, 'teller')), 'web')->get($url)->assertNotFound(); // someone else's labels
});

it('prints labels for the ticked products from the products list', function () {
    $this->actingAs(withMfa($this->owner), 'web');
    Livewire::test(ListProducts::class)
        ->callTableBulkAction('labels', [$this->gari->id], ['kind' => 'barcode', 'layout' => 'roll_50x25', 'copies' => 1, 'give' => true])
        ->assertNotified('Labels ready');
    expect($this->gari->fresh('units.barcodes')->units->first()->barcodes)->toHaveCount(1);
});

it('keeps a photo on an adjustment line, and requires one for losses when the company asks', function () {
    Storage::disk('local')->put('stock-photos/broken.jpg', 'jpeg');
    app(InventoryService::class)->adjust($this->a, $this->hq, $this->milo, $this->milo->units->first(), '10', 'opening', '');
    app(Settings::class)->set($this->a, 'stock.adjustment_photos', 'losses');
    $svc = app(StockAdjustmentService::class);
    $adj = $svc->create($this->a, $this->hq, ['reason_code' => 'damaged']);
    $line = $svc->addLine($this->a, $adj, ['product_id' => $this->milo->id, 'mode' => 'remove', 'qty' => '1']);
    expect(fn () => $svc->submit($this->a, $adj->fresh()))->toThrow(DomainException::class, 'Line 1: add a photo');

    $svc->removeLine($this->a, $adj, $line->id);
    $line = $svc->addLine($this->a, $adj, ['product_id' => $this->milo->id, 'mode' => 'remove', 'qty' => '1', 'photo_path' => 'stock-photos/broken.jpg']);
    $svc->submit($this->a, $adj->fresh());
    expect(StockAdjustment::find($adj->id)->status)->toBe('submitted');

    $this->actingAs(withMfa($this->owner), 'web')->get(route('stock-photo', $line->id))->assertOk();
    $this->actingAs(withMfa(staff($this->owner, 'clerk2', 2)), 'web')->get(route('stock-photo', $line->id))->assertForbidden();
    // A path that was not uploaded here is ignored.
    $adj2 = $svc->create($this->a, $this->hq, ['reason_code' => 'found']);
    expect($svc->addLine($this->a, $adj2, ['product_id' => $this->milo->id, 'mode' => 'add', 'qty' => '1', 'photo_path' => '../.env'])->photo_path)->toBeNull();
});
