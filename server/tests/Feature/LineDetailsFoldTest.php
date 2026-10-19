<?php

use App\Domain\Catalog\CatalogService;
use App\Filament\Resources\StockAdjustments\Pages\CreateStockAdjustment;
use App\Filament\Resources\StockAdjustments\Pages\EditStockAdjustment;
use App\Filament\Support\Fields;
use App\Models\StockAdjustment;
use App\Models\Warehouse;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00');
    $this->phone = makeProduct($this->owner, 'PHN', 'Phone X', retail: '900.00');
    app(CatalogService::class)->saveProduct(actorFor($this->owner), $this->phone, ['track_serials' => true]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner));
});

it('folds a line\'s expiry, reason and note under More details, and asks for serials only for serial-numbered products', function () {
    $unit = $this->milo->units->first();
    expect(Fields::serialised($unit->id))->toBeFalse()->and(Fields::serialised($this->phone->fresh()->units->first()->id))->toBeTrue();

    Livewire::test(CreateStockAdjustment::class)->assertSee('More details')->assertDontSee('Serial / IMEI')
        ->fillForm(['warehouse_id' => $this->hq->id, 'reason_code' => 'correction',
            'lines' => [['product_uom_id' => $unit->id, 'mode' => 'add', 'qty' => '3', 'expires_on' => '2027-05-31', 'note' => 'Found on the shelf']]])
        ->call('create')->assertHasNoFormErrors();
    $a = StockAdjustment::sole();
    expect($a->lines()->sole()->note)->toBe('Found on the shelf');

    // An edited draft keeps what was typed under More details (opened because it holds values).
    $lines = Livewire::test(EditStockAdjustment::class, ['record' => $a->id])->assertSee('More details')->get('data.lines');
    expect(array_values($lines)[0]['note'])->toBe('Found on the shelf');
});
