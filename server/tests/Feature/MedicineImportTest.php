<?php

use App\Domain\Imports\Importers\ProductsUpdateImporter;
use App\Domain\Support\DomainException;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Product;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    enableModule($this->owner, $this->branches['HQ'], 'pharmacy');
    $this->amox = makeProduct($this->owner, 'AMOX', 'Amoxicillin 500mg caps', retail: '2.00');
    $this->peth = makeProduct($this->owner, 'PETH', 'Pethidine 50mg', retail: '20.00');
});

it('sets the medicine schedule and details from the products file, switching batches on', function () {
    $importer = app(ProductsUpdateImporter::class);
    $importer->apply(actorFor($this->owner), ['sku' => 'AMOX', 'drug_schedule' => 'Prescription only', 'generic_name' => 'Amoxicillin', 'strength' => '500 MG',
        'dosage_form' => 'Capsule', 'fda_reg_no' => 'FDA/SD.123', 'active_ingredients' => 'Amoxicillin'], [], null);
    $a = $this->amox->fresh();
    expect($a->drug_schedule)->toBe('pom')->and($a->track_batches)->toBeTrue()->and($a->strength)->toBe('500 MG')
        ->and($a->active_ingredients)->toBe(['amoxicillin'])->and($a->needsPrescription())->toBeTrue();

    $importer->apply(actorFor($this->owner), ['sku' => 'PETH', 'drug_schedule' => 'CD'], [], null);
    expect($this->peth->fresh()->drug_schedule)->toBe('controlled');

    $rows = collect(iterator_to_array($importer->exportRows(actorFor($this->owner), [], null), false));
    $headings = array_column($importer->columns(), 'name');
    $amox = array_combine(array_slice($headings, 0, count($rows->first())), array_slice($rows->firstWhere(0, 'AMOX'), 0, count($headings)));
    expect($amox['drug_schedule'])->toBe('pom')->and($amox['generic_name'])->toBe('AMOXICILLIN')->and($amox['active_ingredients'])->toBe('amoxicillin');

    expect(fn () => $importer->apply(actorFor($this->owner), ['sku' => 'AMOX', 'drug_schedule' => 'maybe'], [], null))->toThrow(DomainException::class, 'drug_schedule');
    $importer->apply(actorFor($this->owner), ['sku' => 'AMOX', 'drug_schedule' => 'none'], [], null);
    expect($this->amox->fresh()->drug_schedule)->toBeNull();
});

it('sets the schedule of ticked products from the products list', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner));
    Livewire::test(ListProducts::class)->callTableBulkAction('schedule', [$this->amox->id, $this->peth->id], ['drug_schedule' => 'controlled'])
        ->assertNotified('2 product(s) updated');
    expect(Product::whereIn('id', [$this->amox->id, $this->peth->id])->pluck('drug_schedule')->unique()->all())->toBe(['controlled'])
        ->and($this->amox->fresh()->track_batches)->toBeTrue();
    Livewire::test(ListProducts::class)->filterTable('drug_schedule', 'controlled')->assertCanSeeTableRecords([$this->amox, $this->peth]);
});
