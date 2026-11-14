<?php

namespace App\Http\Middleware;

use App\Domain\Access\SignInGuard;
use App\Models\User;
use App\Security\Passkeys\PasskeyLoginController;
use Closure;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Filament's own "MFA required" switch is panel-wide. Ours is per person: anyone at or above
 * the configured security level (Settings → security.mfa_from_level) must either sign in with
 * a passkey (user-verified) or have an authenticator app set up before using the back office.
 * Owning a passkey is not enough on its own: a password sign-in by someone with a passkey
 * but no authenticator app would otherwise be single-factor.
 */
class EnsureMfaForLevel
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User || ! $user->requiresMfa()) {
            return $next($request);
        }
        if ($request->session()->get(PasskeyLoginController::SESSION_KEY) === $user->id) {
            return $next($request);
        }
        // IT and SyncLytics support: the authenticator app or a passkey, not a WhatsApp or e-mail code alone (a code by
        // message can be intercepted; owner decision 2026-11-13).
        if (SignInGuard::isStrict($user)) {
            return filled($user->app_authentication_secret) ? $next($request) : redirect()->guest(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
        }
        if (MultiFactorChallenge::make()->hasEnabledProviders($user)) {
            return $next($request);
        }

        return redirect()->guest(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
    }
}
