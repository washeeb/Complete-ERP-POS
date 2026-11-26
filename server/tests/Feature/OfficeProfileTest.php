<?php

use App\Models\AuditEvent;
use App\Models\Passkey;
use App\Security\Passkeys\PasskeyLoginController;
use App\Security\ScopeType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

// My profile in the new back office (part 39): details, look, preferences, notifications, password, photo, two-factor
// sign-in and passkeys; and the new back office asking for two-factor set-up, as the current one does.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->owner->forceFill(['password' => 'Owner-pass-2026!'])->saveQuietly();
    $this->owner = withMfa($this->owner->fresh());
});

it('saves details, look, preferences and notifications, and changes the password only with the current one', function () {
    $this->actingAs($this->owner, 'web');
    $p = $this->getJson('/office-api/profile')->assertOk()->json();
    expect($p)->toMatchArray(['name' => $this->owner->name, 'has_photo' => false, 'look' => ''])->and($p['two_factor'])->toMatchArray(['enabled' => true, 'required' => true])
        ->and(collect($p['topics'])->pluck('id'))->toContain('approvals');
    // The new look's menu choice is kept although this page sends only its own preferences.
    $this->owner->forceFill(['ui_prefs' => ['office_look' => 'side']])->saveQuietly();
    $body = ['name' => 'Ama Owner', 'email' => $this->owner->email, 'look' => 'side', 'prefs' => ['rows' => 50, 'theme' => 'dark', 'date' => 'iso', 'density' => 'compact', 'home' => 'dashboard'],
        'notify' => ['approvals' => ['in_app'], 'summary' => []]];
    $this->postJson('/office-api/profile', $body)->assertOk();
    $u = $this->owner->fresh();
    expect($u->ui_look)->toBe('side')->and($u->ui_prefs)->toMatchArray(['rows' => 50, 'theme' => 'dark', 'date' => 'iso', 'density' => 'compact', 'office_look' => 'side'])
        ->and($u->notify_prefs['approvals'])->toBe(['in_app' => true, 'push' => false])->and($u->notify_prefs['summary']['email'])->toBeFalse();

    $this->postJson('/office-api/profile', $body + ['password' => 'A-much-longer-phrase-9', 'password_confirmation' => 'A-much-longer-phrase-9'])->assertStatus(422)
        ->assertJsonValidationErrors('current_password');
    $this->postJson('/office-api/profile', ['email' => 'new@demo.test'] + $body)->assertStatus(422)->assertJsonValidationErrors('current_password');
    $this->postJson('/office-api/profile', $body + ['password' => 'A-much-longer-phrase-9', 'password_confirmation' => 'different', 'current_password' => 'Owner-pass-2026!'])
        ->assertStatus(422)->assertJsonValidationErrors('password');
    $this->postJson('/office-api/profile', $body + ['password' => 'A-much-longer-phrase-9', 'password_confirmation' => 'A-much-longer-phrase-9', 'current_password' => 'Owner-pass-2026!'])
        ->assertOk();
    expect(Hash::check('A-much-longer-phrase-9', $this->owner->fresh()->password))->toBeTrue()
        ->and(session('password_hash_web'))->toBe($this->owner->fresh()->password); // still signed in
    $this->postJson('/office-api/profile', ['look' => 'neon'] + $body)->assertStatus(422);
});

it('keeps a photo on the company server, and removes it', function () {
    $this->actingAs($this->owner, 'web');
    $this->postJson('/office-api/profile/photo', ['photo' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')])->assertStatus(422);
    $this->post('/office-api/profile/photo', ['photo' => UploadedFile::fake()->image('me.jpg', 400, 300)], ['Accept' => 'application/json'])->assertOk();
    expect($this->owner->fresh()->avatar_path)->not->toBeNull()->and(AuditEvent::where('action', 'user.photo_changed')->exists())->toBeTrue();
    $this->postJson('/office-api/profile/photo', ['remove' => true])->assertOk();
    expect($this->owner->fresh()->avatar_path)->toBeNull();
});

it('sets up two-factor sign-in with the app, makes new recovery codes and turns it off with one', function () {
    $kofi = staff($this->owner, 'kofi', 3, 'cashier', ScopeType::Branch, $this->b['HQ']->id);
    $kofi->forceFill(['password' => 'Kofi-pass-2026!', 'signin_method' => 'password'])->saveQuietly();
    $this->actingAs($kofi->fresh(), 'web');
    expect($this->getJson('/office-api/profile')->assertOk()->json('two_factor'))->toMatchArray(['enabled' => false, 'required' => false]);
    $start = $this->postJson('/office-api/profile/two-factor/start')->assertOk()->json();
    expect($start['qr'])->toStartWith('data:image/')->and($kofi->fresh()->app_authentication_secret)->toBeNull(); // nothing saved yet
    $code = fn () => (new Google2FA)->getCurrentOtp($start['secret']);
    $this->postJson('/office-api/profile/two-factor/confirm', ['token' => $start['token'], 'code' => '000000', 'password' => 'Kofi-pass-2026!'])->assertStatus(422)->assertJsonValidationErrors('code');
    $this->postJson('/office-api/profile/two-factor/confirm', ['token' => $start['token'], 'code' => $code(), 'password' => 'wrong'])->assertStatus(422)->assertJsonValidationErrors('password');
    $this->postJson('/office-api/profile/two-factor/confirm', ['token' => 'forged', 'code' => $code(), 'password' => 'Kofi-pass-2026!'])->assertStatus(422);
    $codes = $this->postJson('/office-api/profile/two-factor/confirm', ['token' => $start['token'], 'code' => $code(), 'password' => 'Kofi-pass-2026!'])->assertOk()->json('recovery_codes');
    expect($codes)->toHaveCount(8)->and($kofi->fresh()->app_authentication_secret)->toBe($start['secret'])
        ->and(Hash::check($codes[0], $kofi->fresh()->app_authentication_recovery_codes[0]))->toBeTrue() // kept only as hashes
        ->and(AuditEvent::where('action', 'auth.mfa_enabled')->where('subject_id', $kofi->id)->exists())->toBeTrue();

    // Someone else's set-up cannot be used.
    $this->actingAs($this->owner->fresh(), 'web');
    $this->postJson('/office-api/profile/two-factor/disable', ['code' => '123456', 'password' => 'wrong'])->assertStatus(422);

    $this->actingAs($kofi->fresh(), 'web');
    $this->travel(1)->minutes(); // a new code (each code works once)
    $new = $this->postJson('/office-api/profile/two-factor/recovery-codes', ['code' => $code(), 'password' => 'Kofi-pass-2026!'])->assertOk()->json('recovery_codes');
    expect($new)->toHaveCount(8)->and($new)->not->toContain($codes[0]);
    $this->postJson('/office-api/profile/two-factor/disable', ['recovery_code' => $codes[0], 'password' => 'Kofi-pass-2026!'])->assertStatus(422); // the old codes stopped working
    $this->postJson('/office-api/profile/two-factor/disable', ['recovery_code' => $new[0], 'password' => 'Kofi-pass-2026!'])->assertOk();
    expect($kofi->fresh()->app_authentication_secret)->toBeNull()->and($kofi->fresh()->app_authentication_recovery_codes)->toBeNull();
});

it('adds passkeys through the browser handshake and removes them', function () {
    $this->actingAs($this->owner, 'web');
    $this->postJson('/office-api/profile/passkeys/options', ['name' => ''])->assertStatus(422);
    $opts = $this->postJson('/office-api/profile/passkeys/options', ['name' => 'Work laptop'])->assertOk()->json('options');
    expect($opts)->toHaveKeys(['challenge', 'rp', 'user'])->and(session('passkey-registration-options'))->not->toBeNull();
    $this->postJson('/office-api/profile/passkeys', ['passkey' => '{"id":"made-up"}'])->assertStatus(422)->assertJsonValidationErrors('name');
    $this->postJson('/office-api/profile/passkeys', ['passkey' => '{}'])->assertStatus(422); // the options are used once

    $pk = new Passkey;
    $pk->setRawAttributes(['authenticatable_id' => $this->owner->id, 'name' => 'Phone', 'credential_id' => 'abc', 'data' => '{}']);
    $pk->save();
    expect($this->getJson('/office-api/profile')->json('passkeys.0'))->toMatchArray(['name' => 'Phone', 'last_used' => 'not yet']);
    $other = staff($this->owner, 'yaw', 2);
    $theirs = new Passkey;
    $theirs->setRawAttributes(['authenticatable_id' => $other->id, 'name' => 'Theirs', 'credential_id' => 'def', 'data' => '{}']);
    $theirs->save();
    $this->postJson("/office-api/profile/passkeys/{$theirs->id}/remove")->assertNotFound(); // only one's own
    $this->postJson("/office-api/profile/passkeys/{$pk->id}/remove")->assertOk();
    expect(Passkey::whereKey($pk->id)->exists())->toBeFalse()->and(AuditEvent::where('action', 'auth.passkey_removed')->exists())->toBeTrue();
});

it('sends someone who needs two-factor sign-in, and has none, to set it up before using the new back office', function () {
    $this->owner->forceFill(['app_authentication_secret' => null])->saveQuietly();
    $this->actingAs($this->owner->fresh(), 'web');
    $r = $this->getJson('/office-api/me')->assertForbidden()->json();
    expect($r['error'])->toBe('mfa_setup')->and($r['url'])->toBe('/office/two-factor-setup');
    $this->get('/office/sales')->assertRedirect();
    // A passkey sign-in counts as two-factor, as in the current back office.
    $this->withSession([PasskeyLoginController::SESSION_KEY => $this->owner->id])->getJson('/office-api/me')->assertOk();
    $this->actingAs(withMfa($this->owner->fresh()), 'web')->withSession([PasskeyLoginController::SESSION_KEY => null])->getJson('/office-api/me')->assertOk();
});
