<?php

namespace App\Security\Passkeys;

use App\Domain\Support\Actor;
use App\Domain\Support\Recorder;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Spatie\LaravelPasskeys\Http\Controllers\AuthenticateUsingPasskeyController;

/**
 * The package signs in whoever owns the passkey. We additionally refuse inactive accounts,
 * record the sign-in in the audit log, and mark the session as passkey-verified so the
 * L3+ two-factor rule is satisfied (see EnsureMfaForLevel).
 */
class PasskeyLoginController extends AuthenticateUsingPasskeyController
{
    public const SESSION_KEY = 'erp.passkey_verified_user';

    private bool $refused = false;

    protected function logInAuthenticatable(Authenticatable $authenticatable, bool $remember = false): self
    {
        if (! $authenticatable instanceof User || ! $authenticatable->canAccessPanel(Filament::getPanel('admin'))) {
            $this->refused = true;

            return $this;
        }
        auth()->login($authenticatable, $remember);
        Session::regenerate();
        Session::put(self::SESSION_KEY, $authenticatable->id);

        app(Recorder::class)->transaction(fn () => app(Recorder::class)->audit
            ->record(Actor::user($authenticatable, 'web'), 'auth.passkey_login', $authenticatable));

        return $this;
    }

    protected function validPasskeyResponse(Request $request): RedirectResponse
    {
        if ($this->refused) {
            return $this->invalidPasskeyResponse();
        }

        return redirect()->intended(Filament::getPanel('admin')->getUrl());
    }
}
