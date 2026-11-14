<?php

namespace App\Http\Middleware;

use App\Domain\Access\SignInGuard;
use App\Domain\Support\Actor;
use App\Domain\Support\AuditLog;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * IT Admin and SyncLytics support are signed out after 15 minutes without a click or key (owner decision 2026-11-13): an
 * unattended screen with the technical side open is the easiest way in. The page's own background refreshes (the bell
 * every 30 seconds) are not activity. resources/views/access/idle.blade.php signs the open page out on time too.
 */
final class SignOutWhenIdle
{
    public const SESSION_KEY = 'erp.active_at';

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        if (! $user instanceof User || ! SignInGuard::isStrict($user)) {
            return $next($request);
        }
        $session = $request->session();
        $last = (int) $session->get(self::SESSION_KEY, 0);
        if ($last && now()->getTimestamp() - $last > SignInGuard::IDLE_MINUTES * 60) {
            DB::transaction(fn () => app(AuditLog::class)->record(Actor::user($user, 'web'), 'auth.idle_signed_out', $user, [], 'No click or key for '.SignInGuard::IDLE_MINUTES.' minutes'));
            Auth::guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();
            if ($request->hasHeader('X-Livewire')) {
                abort(419); // the page says "You were signed out" (layout/connection-notices)
            }

            return redirect()->guest(Filament::getPanel('admin')->getLoginUrl());
        }
        if (! $last || self::isActivity($request)) {
            $session->put(self::SESSION_KEY, now()->getTimestamp());
        }

        return $next($request);
    }

    /** A page opened, or something the person did on it; not a background refresh (only "$refresh" calls, nothing typed). */
    public static function isActivity(Request $request): bool
    {
        if (! $request->hasHeader('X-Livewire')) {
            return true;
        }
        foreach ((array) $request->input('components', []) as $c) {
            if (! empty($c['updates'])) {
                return true;
            }
            foreach ((array) ($c['calls'] ?? []) as $call) {
                if (($call['method'] ?? '') !== '$refresh') {
                    return true;
                }
            }
        }

        return false;
    }
}
