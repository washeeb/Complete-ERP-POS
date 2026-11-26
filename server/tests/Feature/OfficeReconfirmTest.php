<?php

use App\Models\AuditEvent;
use App\Models\Passkey;
use App\Security\Reconfirm;
use App\Security\ScopeType;
use PragmaRX\Google2FA\Google2FA;

// "Confirm it's you" in the new back office (part 40): the app code, a code sent, the password for someone with neither
// an app nor a passkey, and a passkey checked without signing in again — five tries a minute, audited.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->owner = withMfa($this->owner);
    Reconfirm::$assumeConfirmed = false;
});

afterEach(fn () => Reconfirm::$assumeConfirmed = true);

it('confirms with the code from the app, refuses a wrong one, and stops after five tries', function () {
    $this->actingAs($this->owner, 'web');
    expect($this->getJson('/office-api/confirm-its-you')->assertOk()->json())->toMatchArray(['confirmed' => false, 'app' => true, 'passkey' => false, 'password' => false]);
    $this->postJson('/office-api/confirm-its-you', ['code' => '000000'])->assertStatus(422)->assertJsonValidationErrors('code');
    $this->postJson('/office-api/confirm-its-you', ['password' => 'anything'])->assertStatus(422); // has an app: the password is not enough
    $code = (new Google2FA)->getCurrentOtp('JBSWY3DPEHPK3PXP');
    $this->postJson('/office-api/confirm-its-you', ['code' => $code])->assertOk()->assertJson(['confirmed' => true, 'minutes' => Reconfirm::MINUTES]);
    expect($this->getJson('/office-api/confirm-its-you')->json('confirmed'))->toBeTrue()
        ->and(AuditEvent::where('action', 'auth.reconfirmed')->where('subject_id', $this->owner->id)->exists())->toBeTrue();

    foreach (range(1, 5) as $i) {
        $this->postJson('/office-api/confirm-its-you', ['code' => '111111']);
    }
    $this->postJson('/office-api/confirm-its-you', ['code' => $code])->assertStatus(422)->assertJsonPath('errors.code.0', 'Too many tries. Wait a minute, then try again.');
});

it('confirms with the password only for someone with neither an app nor a passkey', function () {
    $esi = staff($this->owner, 'esi', 3, 'cashier', ScopeType::Branch, $this->b['HQ']->id);
    $esi->forceFill(['password' => 'Esi-pass-2026!', 'signin_method' => 'password'])->saveQuietly();
    $this->actingAs($esi->fresh(), 'web');
    expect($this->getJson('/office-api/confirm-its-you')->json())->toMatchArray(['app' => false, 'passkey' => false, 'password' => true]);
    $this->postJson('/office-api/confirm-its-you', ['password' => 'wrong'])->assertStatus(422)->assertJsonValidationErrors('password');
    $this->postJson('/office-api/confirm-its-you', ['password' => 'Esi-pass-2026!'])->assertOk();
    expect($this->getJson('/office-api/confirm-its-you')->json('confirmed'))->toBeTrue();
});

it('accepts a code sent by message only after one was sent', function () {
    $this->actingAs($this->owner, 'web');
    $this->postJson('/office-api/confirm-its-you', ['code' => '123456'])->assertStatus(422);
    $sent = $this->postJson('/office-api/confirm-its-you/send')->assertOk()->json('sent_to');
    expect($sent)->toStartWith('e-mail ')->and($this->getJson('/office-api/confirm-its-you')->json('sent_to'))->toBe($sent);
});

it('asks a passkey for its challenge, and refuses an answer that is not the person\'s own passkey', function () {
    $this->actingAs($this->owner, 'web');
    $this->postJson('/office-api/confirm-its-you/passkey/options')->assertStatus(422); // no passkey yet
    $pk = new Passkey;
    $pk->setRawAttributes(['authenticatable_id' => $this->owner->id, 'name' => 'Phone', 'credential_id' => 'abc', 'data' => '{}']);
    $pk->save();
    $opts = $this->postJson('/office-api/confirm-its-you/passkey/options')->assertOk()->json('options');
    expect($opts['userVerification'])->toBe('required')->and(session('passkey-authentication-options'))->toBeNull(); // not a sign-in challenge
    $this->postJson('/office-api/confirm-its-you/passkey', ['answer' => '{"id":"made-up","type":"public-key"}'])->assertStatus(422)->assertJsonValidationErrors('answer');
    $this->postJson('/office-api/confirm-its-you/passkey', ['answer' => '{}'])->assertStatus(422); // the challenge works once
    expect($this->getJson('/office-api/confirm-its-you')->json('confirmed'))->toBeFalse();
});
