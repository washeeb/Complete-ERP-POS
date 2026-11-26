<?php

use App\Mail\PlainMessage;
use App\Models\AuditEvent;
use App\Models\User;
use App\Security\ScopeType;
use PragmaRX\Google2FA\Google2FA;

// Signing in to the new back office (part 41): the e-mail or username, five tries a minute, inactive accounts refused,
// the two-factor challenge (app code, recovery code, a code sent), the password checked again, signing out; the sign-in
// page open signed out, and the two-factor rule sending people to the new set-up page.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->esi = staff($this->owner, 'esi', 3, 'cashier', ScopeType::Branch, $this->b['HQ']->id);
    $this->esi->forceFill(['password' => 'Esi-pass-2026!', 'signin_method' => 'password'])->saveQuietly();
    $this->owner->forceFill(['password' => 'Owner-pass-2026!'])->saveQuietly();
    $this->owner = withMfa($this->owner->fresh());
});

it('opens the sign-in page signed out, and the back office sends a signed-out person there', function () {
    $this->get('/office/sign-in')->assertOk();
    $this->get('/office/sales')->assertRedirect('/office/sign-in');
    expect(session('url.intended'))->toEndWith('/office/sales');
    $state = $this->getJson('/office-auth/state')->assertOk()->json();
    expect($state)->toHaveKeys(['company', 'logo', 'tagline'])->and($state['signed_in'])->toBeFalse()->and($state['challenge'])->toBeNull();
});

it('signs in with the username and the password, back to the page asked for, and signs out', function () {
    $this->get('/office/stock'); // asked for while signed out
    $this->postJson('/office-auth/sign-in', ['login' => 'ESI', 'password' => 'wrong'])->assertStatus(422)->assertJsonValidationErrors('login');
    expect(AuditEvent::where('action', 'auth.sign_in_failed')->exists())->toBeTrue();
    $r = $this->postJson('/office-auth/sign-in', ['login' => 'ESI', 'password' => 'Esi-pass-2026!'])->assertOk()->json();
    expect($r)->toMatchArray(['signed_in' => true, 'open' => '/office/stock'])->and(auth()->id())->toBe($this->esi->id)
        ->and(AuditEvent::where('action', 'auth.signed_in')->where('actor_id', $this->esi->id)->exists())->toBeTrue();
    $this->getJson('/office-api/me')->assertOk();
    $this->get('/office/sign-in')->assertRedirect(); // signed in already: on to the back office
    $this->postJson('/office-api/sign-out')->assertOk()->assertJson(['open' => '/office/sign-in']);
    expect(auth()->check())->toBeFalse();
});

it('refuses an inactive account the same way as a wrong password, and stops after five tries', function () {
    $this->esi->forceFill(['is_active' => false])->saveQuietly();
    $this->postJson('/office-auth/sign-in', ['login' => 'esi', 'password' => 'Esi-pass-2026!'])->assertStatus(422)
        ->assertJsonPath('errors.login.0', 'These details do not match an account that may sign in here.');
    foreach (range(1, 4) as $i) {
        $this->postJson('/office-auth/sign-in', ['login' => 'nobody', 'password' => 'x']);
    }
    $this->postJson('/office-auth/sign-in', ['login' => 'nobody', 'password' => 'x'])->assertStatus(422)
        ->assertJson(fn ($j) => $j->where('errors.login.0', fn ($m) => str_starts_with($m, 'Too many tries'))->etc());
    expect(auth()->check())->toBeFalse();
});

it('asks for the code from the app, then signs in; a recovery code works once', function () {
    $r = $this->postJson('/office-auth/sign-in', ['login' => $this->owner->email, 'password' => 'Owner-pass-2026!'])->assertOk()->json();
    expect($r['challenge'])->toMatchArray(['provider' => 'app'])->and(auth()->check())->toBeFalse(); // not signed in before the code
    expect($this->getJson('/office-auth/state')->json('challenge.provider'))->toBe('app');
    $this->postJson('/office-auth/verify', ['code' => '000000'])->assertStatus(422)->assertJsonValidationErrors('code');
    $code = (new Google2FA)->getCurrentOtp('JBSWY3DPEHPK3PXP');
    $this->postJson('/office-auth/verify', ['code' => $code])->assertOk()->assertJson(['signed_in' => true]);
    expect(auth()->id())->toBe($this->owner->id);
    $this->postJson('/office-api/sign-out')->assertOk();

    // A recovery code instead of the app.
    $this->owner->saveAppAuthenticationRecoveryCodes([Hash::make('rec-one-1234567890')]);
    $this->postJson('/office-auth/sign-in', ['login' => $this->owner->email, 'password' => 'Owner-pass-2026!'])->assertOk();
    $this->postJson('/office-auth/verify', ['recovery_code' => 'rec-one-1234567890'])->assertOk();
    $this->postJson('/office-api/sign-out')->assertOk();
    $this->postJson('/office-auth/sign-in', ['login' => $this->owner->email, 'password' => 'Owner-pass-2026!'])->assertOk();
    $this->postJson('/office-auth/verify', ['recovery_code' => 'rec-one-1234567890'])->assertStatus(422); // used
});

it('signs in with a code sent by e-mail for someone set to "password and a code", and re-checks the password', function () {
    Mail::fake();
    $this->esi->forceFill(['signin_method' => 'code'])->saveQuietly();
    $r = $this->postJson('/office-auth/sign-in', ['login' => 'esi', 'password' => 'Esi-pass-2026!'])->assertOk()->json();
    expect($r['challenge']['provider'])->toBe('signin_code');
    Mail::assertSent(PlainMessage::class);
    $this->postJson('/office-auth/choose', ['provider' => 'signin_code', 'email' => true])->assertOk()->assertJsonPath('sent_to', fn ($v) => str_starts_with($v, 'e-mail '));
    // The password changed meanwhile: the code alone does not let the old one in.
    [$hash] = session('signin_code:'.$this->esi->id);
    session()->put('signin_code:'.$this->esi->id, [Hash::make('424242'), now()->addMinutes(10)->getTimestamp()]);
    User::find($this->esi->id)->forceFill(['password' => 'Changed-pass-2026!'])->saveQuietly();
    $this->postJson('/office-auth/verify', ['code' => '424242'])->assertStatus(422);
    expect(auth()->check())->toBeFalse();
    unset($hash);
});

it('sends someone who needs two-factor sign-in to the new set-up page, which stays open to them', function () {
    $this->owner->forceFill(['app_authentication_secret' => null])->saveQuietly();
    $this->actingAs($this->owner->fresh(), 'web');
    $this->get('/office/sales')->assertRedirect('/office/two-factor-setup');
    $this->get('/office/two-factor-setup')->assertOk();
    $this->getJson('/office-api/me')->assertForbidden()->assertJsonPath('url', '/office/two-factor-setup');
    expect($this->getJson('/office-api/two-factor-setup')->assertOk()->json())->toMatchArray(['enabled' => false, 'required' => true]);
    $start = $this->postJson('/office-api/profile/two-factor/start')->assertOk()->json();
    $this->postJson('/office-api/profile/two-factor/confirm', ['token' => $start['token'], 'code' => (new Google2FA)->getCurrentOtp($start['secret']), 'password' => 'Owner-pass-2026!'])
        ->assertOk();
    $this->getJson('/office-api/me')->assertOk();
});
