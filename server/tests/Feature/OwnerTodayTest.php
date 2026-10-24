<?php

use App\Domain\Reports\OwnerToday;
use App\Filament\Pages\OwnerTodayPage;
use App\Security\ScopeType;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** The owner's phone view (owner request 2026-10-24). */
beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
});

it('shows the business today on one screen for the owner, and not for a cashier', function () {
    $d = app(OwnerToday::class)->build($this->tenant->fresh(), null);
    expect($d['branches'])->toHaveCount(count($this->branches))->and($d['payments'])->toHaveKeys(['Cash', 'Mobile money', 'Card'])
        ->and($d['branches'][0]['closed'])->toBeNull();

    $this->actingAs(withMfa($this->owner));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(OwnerTodayPage::class)->assertSee('Sales')->assertSee('Day not closed yet')->set('yesterday', true)->assertSee('Yesterday');
    $this->get('/admin/today')->assertOk()->assertSee('admin.webmanifest', false);

    $this->actingAs(withMfa(staff($this->owner, 'efua', 2, 'teller', ScopeType::Branch, $this->branches['HQ']->id)));
    expect($this->get('/admin/today')->status())->toBeIn([302, 403]); // turned away (to the page that says why)
});
