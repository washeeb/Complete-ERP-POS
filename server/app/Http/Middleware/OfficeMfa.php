<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The same two-factor rule as the current back office (EnsureMfaForLevel) for the new one: someone whose account needs
 * two-factor sign-in, and has none set up, is sent to set it up first. The page itself is redirected; its calls are
 * refused with where to go (the new back office then opens that page), so nothing is used without it.
 */
class OfficeMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $passed = false;
        $out = app(EnsureMfaForLevel::class)->handle($request, function ($r) use ($next, &$passed) {
            $passed = true;

            return $next($r);
        });
        if ($passed || ! $out instanceof RedirectResponse || ! $request->expectsJson()) {
            return $out;
        }

        return response()->json(['error' => 'mfa_setup', 'detail' => 'Your account needs two-factor sign-in. Set it up first.', 'url' => $out->getTargetUrl()], 403);
    }
}
