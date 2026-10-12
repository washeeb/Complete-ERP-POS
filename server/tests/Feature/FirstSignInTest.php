<?php

use App\Domain\Licensing\LicenseManager;
use App\Domain\Org\TenantInstaller;
use App\Filament\Pages\LicensePage;
use App\Filament\Pages\SetupWizard;
use Filament\Facades\Filament;

// A real first installation: the company exists, but it is neither activated nor set up. The licence comes first, then
// the setup wizard; the two redirects must never send each other's page back and forth (it looped on a test PC).
it('sends a brand-new installation to the licence page first, and to the setup wizard once activated', function () {
    [, $owner] = app(TenantInstaller::class)->install('Fresh Ltd', [
        'name' => 'Ama Owner', 'username' => 'ama', 'email' => 'ama@fresh.test', 'password' => 'correct-horse-battery',
    ]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($owner));

    $this->get('/admin')->assertRedirect(LicensePage::getUrl());
    $this->get(LicensePage::getUrl())->assertOk()->assertSee('SYNREQ-');
    $this->get(SetupWizard::getUrl())->assertRedirect(LicensePage::getUrl());

    activateTestLicense();
    LicenseManager::forget();
    $this->get('/admin')->assertRedirect(SetupWizard::getUrl());
    $this->get(SetupWizard::getUrl())->assertOk();
});
