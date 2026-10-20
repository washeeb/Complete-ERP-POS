<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Inventory\InventoryService;
use App\Filament\Resources\StockAdjustments\Pages\CreateStockAdjustment;
use App\Filament\Resources\StockAdjustments\Pages\EditStockAdjustment;
use App\Filament\Resources\StockRequests\Pages\CreateStockRequest;
use App\Filament\Resources\StockTransfers\Pages\CreateStockTransfer;
use App\Filament\Support\Fields;
use App\Models\StockAdjustment;
use App\Models\Warehouse;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00']);
    $this->phone = makeProduct($this->owner, 'PHN', 'Phone X', retail: '900.00');
    app(CatalogService::class)->saveProduct(actorFor($this->owner), $this->phone, ['track_serials' => true]);
    $this->piece = $this->milo->units->firstWhere('is_base', true);
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->hq, $this->milo, $this->piece, '48', 'opening', '', null, '10.00'); // 2 cartons at GHS 10 a tin
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner));
});

it('keeps a line on one row: available, after, value, and an end-of-line Details drop-down for batch, expiry, cost, reason and note', function () {
    $ctn = $this->milo->units->firstWhere('is_base', false);
    expect(Fields::serialised($this->piece->id))->toBeFalse()->and(Fields::serialised($this->phone->fresh()->units->first()->id))->toBeTrue()
        ->and(Fields::stockOf($ctn->id, $this->hq->id))->toMatchArray(['qty' => 2.0, 'unit' => 'CTN', 'factor' => 24.0]);

    $page = Livewire::test(CreateStockAdjustment::class)->assertSee('Details')->assertDontSee('Serial / IMEI')
        ->fillForm(['warehouse_id' => $this->hq->id, 'reason_code' => 'correction',
            'lines' => [['product_uom_id' => $ctn->id, 'mode' => 'remove', 'qty' => '3', 'note' => 'Damaged in the rain'],
                ['product_uom_id' => $this->piece->id, 'mode' => 'remove', 'qty' => '1']]]);
    // 2 cartons available; removing 3 leaves −1 (shown in red); 72 tins at GHS 10 = GHS 720, above the GHS 500 approval limit.
    $page->assertSee('2 CTN')->assertSee('-1 CTN')->assertSee('GH₵ 720.00')->assertSee('submit it for a manager to approve')
        ->assertSee('also on line 1'); // same product on two lines
    $page->call('create')->assertHasNoFormErrors();
    $a = StockAdjustment::sole();
    expect($a->lines()->orderBy('line_no')->first()->note)->toBe('Damaged in the rain');

    // An edited draft opens the Details of the line that holds extras, and keeps them.
    $lines = array_values(Livewire::test(EditStockAdjustment::class, ['record' => $a->id])->get('data.lines'));
    expect($lines[0]['note'])->toBe('Damaged in the rain')->and($lines[0]['more'])->toBeTrue()->and($lines[1]['more'])->toBeFalse();
});

it('shows the stock available on transfer and stock request lines, and numbers them', function () {
    $ctn = $this->milo->units->firstWhere('is_base', false);
    $ba = Warehouse::where('branch_id', $this->branches['BA']->id)->first();
    Livewire::test(CreateStockTransfer::class)
        ->fillForm(['from_warehouse_id' => $this->hq->id, 'to_warehouse_id' => $ba->id, 'lines' => [['product_uom_id' => $ctn->id, 'qty' => '1']]])
        ->assertSee('Available to send')->assertSee('2 CTN');
    Livewire::test(CreateStockRequest::class)
        ->fillForm(['to_warehouse_id' => $ba->id, 'from_warehouse_id' => $this->hq->id, 'lines' => [['product_uom_id' => $ctn->id, 'qty' => '1']]])
        ->assertSee('You have')->assertSee('0 CTN')->assertSee('They have')->assertSee('2 CTN');
});
