<?php

use App\Domain\Purchasing\SupplierService;
use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Models\Supplier;
use Livewire\Livewire;

// The box cleared on the form used to reach the database as nothing, and the page failed with a server error.
it('treats empty payment terms as 0 days, on a new supplier and an existing one', function (mixed $empty) {
    [, $owner] = installCompany();
    setUpBranches($owner);
    $this->actingAs(withMfa($owner), 'web');

    Livewire::test(CreateSupplier::class)->fillForm(['name' => 'East Pharmacy', 'payment_terms_days' => $empty])->call('create')->assertHasNoFormErrors();
    $supplier = Supplier::first();
    expect($supplier->payment_terms_days)->toBe(0);

    Livewire::test(CreateSupplier::class)->fillForm(['name' => 'West Pharmacy', 'payment_terms_days' => 30])->call('create')->assertHasNoFormErrors();
    $west = Supplier::where('payment_terms_days', 30)->first();
    Livewire::test(EditSupplier::class, ['record' => $west->id])->fillForm(['payment_terms_days' => $empty])->call('save')->assertHasNoFormErrors();
    expect($west->fresh()->payment_terms_days)->toBe(0);
})->with([null, '']);

it('always gives a new supplier the next code on the form; an import can still bring its own', function () {
    [, $owner] = installCompany();
    setUpBranches($owner);
    $this->actingAs(withMfa($owner), 'web');

    Livewire::test(CreateSupplier::class)->assertFormFieldIsDisabled('code')->fillForm(['name' => 'East Pharmacy'])->call('create')->assertHasNoFormErrors();
    app(SupplierService::class)->save(actorFor($owner), new Supplier, ['name' => 'West Pharmacy', 'code' => 'west-1']);
    expect(Supplier::orderBy('name')->pluck('code')->all())->sequence(fn ($code) => $code->toStartWith('SUP-'), fn ($code) => $code->toBe('WEST-1'));
});
