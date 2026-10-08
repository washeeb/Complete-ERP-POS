<?php

use App\Filament\Resources\Products\Pages\ListProducts;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->o = actorFor($this->owner);
});

it('exports any back-office list to CSV and PDF with the current search', function () {
    makeProduct($this->owner, 'RICE-5', 'Rice 5 kg', retail: '120.00');
    makeProduct($this->owner, 'OIL-1', 'Cooking oil 1 L', retail: '45.00');
    $this->actingAs(withMfa($this->owner));

    $csv = Livewire::test(ListProducts::class)->set('tableSearch', 'rice')
        ->callAction(TestAction::make('exportCsv')->table())->assertFileDownloaded();
    $pdf = Livewire::test(ListProducts::class)->callAction(TestAction::make('exportPdf')->table())->assertFileDownloaded();
});
