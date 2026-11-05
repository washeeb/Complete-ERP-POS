<?php

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
