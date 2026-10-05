<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Filament's own "MFA required" switch is panel-wide. Ours is per person: anyone at or above
 * the configured security level (Settings → security.mfa_from_level) must set up an
 * authenticator before using the back office.
 */
class EnsureMfaForLevel
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User || ! $user->requiresMfa()) {
            return $next($request);
        }
        if (MultiFactorChallenge::make()->hasEnabledProviders($user)) {
            return $next($request);
        }

        return redirect()->guest(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
    }
}
