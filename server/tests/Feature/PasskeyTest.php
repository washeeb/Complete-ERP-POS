<?php

use App\Filament\Auth\EditProfile;
use App\Filament\Resources\Users\UserResource;
use App\Models\AuditEvent;
use App\Models\Passkey;
use App\Security\Passkeys\PasskeyLoginController;
use Filament\Facades\Filament;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    setUpBranches($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('asks authenticators to verify the person, with a strong challenge', function () {
    $json = $this->get(route('passkeys.authentication_options'))->assertOk()->json();

    expect($json['userVerification'])->toBe('required')
        ->and(strlen(base64_decode(strtr($json['challenge'], '-_', '+/'))))->toBeGreaterThanOrEqual(32);
});

it('treats a passkey sign-in as two-factor for L3+ people', function () {
    $this->actingAs($this->owner)->get(UserResource::getUrl())->assertRedirect();

    $this->withSession([PasskeyLoginController::SESSION_KEY => $this->owner->id])
        ->get(UserResource::getUrl())->assertOk();
});

it('does not let a passkey flag for someone else satisfy two-factor', function () {
    $this->actingAs($this->owner)
        ->withSession([PasskeyLoginController::SESSION_KEY => '00000000-0000-0000-0000-000000000000'])
        ->get(UserResource::getUrl())->assertRedirect();
});

it('audits adding and removing a passkey', function () {
    $this->actingAs($this->owner);
    $pk = new Passkey;
    $pk->setRawAttributes(['authenticatable_id' => $this->owner->id, 'name' => 'Work laptop', 'credential_id' => 'abc', 'data' => '{}']);
    $pk->save();
    $pk->delete();

    expect(AuditEvent::where('action', 'auth.passkey_added')->where('subject_id', $this->owner->id)->exists())->toBeTrue()
        ->and(AuditEvent::where('action', 'auth.passkey_removed')->exists())->toBeTrue();
});

it('refuses a passkey sign-in for a deactivated account', function () {
    $gone = staff($this->owner, 'gone', 2);
    $gone->forceFill(['is_active' => false])->saveQuietly();

    $controller = new PasskeyLoginController;
    (fn () => $this->logInAuthenticatable($gone->fresh()))->call($controller);

    expect(auth()->check())->toBeFalse();
});

it('audits turning the authenticator app on and off', function () {
    $this->owner->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    $this->owner->saveAppAuthenticationSecret(null);

    expect(AuditEvent::where('action', 'auth.mfa_enabled')->exists())->toBeTrue()
        ->and(AuditEvent::where('action', 'auth.mfa_disabled')->exists())->toBeTrue()
        ->and(json_encode(AuditEvent::where('action', 'auth.mfa_enabled')->first()->changes))->not->toContain('JBSWY3DPEHPK3PXP');
});

it('saves profile edits through the audited service', function () {
    $this->actingAs($this->owner)->withSession([PasskeyLoginController::SESSION_KEY => $this->owner->id]);

    Livewire\Livewire::test(EditProfile::class)
        ->set('data.name', 'Ama K. Owner')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->owner->fresh()->name)->toBe('Ama K. Owner')
        ->and(AuditEvent::where('action', 'user.profile_updated')->exists())->toBeTrue();
});

it('offers passkey sign-in on the login page and passkey management on the profile page', function () {
    $this->get('/admin/login')->assertOk()->assertSee('Sign in with a passkey')
        ->assertSee('vendor/simplewebauthn/browser-13.3.0.umd.min.js');

    $this->actingAs($this->owner)->withSession([PasskeyLoginController::SESSION_KEY => $this->owner->id])
        ->get('/admin/profile')->assertOk()->assertSee('Add passkey');
});
