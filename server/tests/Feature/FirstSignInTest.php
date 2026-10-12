<?php

use App\Domain\Licensing\LicenseManager;
use App\Domain\Org\TenantInstaller;
use App\Filament\Pages\LicensePage;
use App\Filament\Pages\SetupWizard;
use App\Models\AuditEvent;
use Filament\Facades\Filament;

// A real first installation: the company exists, but it is neither activated nor set up. The licence comes first, then
// the setup wizard; the two redirects must never send each other's page back and forth (it looped on a test PC).
it('sends a brand-new installation to the licence page first, and to the setup wizard once activated', function () {
    [, $owner] = app(TenantInstaller::class)->install('Fresh Ltd', [
        'name' => 'Ama Owner', 'username' => 'ama', 'email' => 'ama@fresh.test', 'password' => 'correct-horse-battery',
    ]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // Two-step sign-in comes before everything, so it can be saved while the installation is still read-only.
    expect(app(LicenseManager::class)->status()['read_only'])->toBeTrue();
    $owner->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    $owner->saveAppAuthenticationRecoveryCodes(['code-1', 'code-2']);
    expect($owner->fresh()->app_authentication_secret)->toBe('JBSWY3DPEHPK3PXP')
        ->and(AuditEvent::withoutGlobalScopes()->where('action', 'auth.mfa_enabled')->exists())->toBeTrue();
    $this->actingAs($owner->fresh());

    $this->get('/admin')->assertRedirect(LicensePage::getUrl());
    $this->get(LicensePage::getUrl())->assertOk()->assertSee('SYNREQ-');
    $this->get(SetupWizard::getUrl())->assertRedirect(LicensePage::getUrl());

    activateTestLicense();
    LicenseManager::forget();
    $this->get('/admin')->assertRedirect(SetupWizard::getUrl());
    $this->get(SetupWizard::getUrl())->assertOk();
});
