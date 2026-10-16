<?php

namespace App\Http\Middleware;

use App\Models\Computer;
use App\Support\ClientInfo;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives each browser that uses the back office a lasting ID (cookie ses_computer, five years) and keeps the computer
 * list up to date: last seen, address, browser and person, at most once every five minutes per computer.
 */
final class TrackComputer
{
    public const COOKIE = 'ses_computer';

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->cookie(self::COOKIE);
        if (! Str::isUuid($key)) {
            $key = (string) Str::uuid7();
            Cookie::queue(Cookie::make(self::COOKIE, $key, 60 * 24 * 365 * 5, '/', null, $request->isSecure(), true, false, 'lax'));
        }
        $request->attributes->set(self::COOKIE, $key); // the audit log reads it for this very request too

        $response = $next($request);

        $user = Auth::guard('web')->user();
        if ($user && $user->tenant_id && Cache::add("computer-seen:{$user->tenant_id}:{$key}", 1, 300)) {
            $computer = Computer::withoutGlobalScopes()->firstOrNew(['tenant_id' => $user->tenant_id, 'computer_key' => $key]);
            $computer->forceFill([
                'tenant_id' => $user->tenant_id, 'computer_key' => $key, 'first_seen_at' => $computer->first_seen_at ?? now(), 'last_seen_at' => now(),
                'last_ip' => $request->ip(), 'last_client' => ClientInfo::from($request), 'last_user_id' => $user->id, 'last_user_name' => $user->name,
                'origin_node' => $computer->origin_node ?? (string) config('erp.node.id'),
            ])->saveQuietly();
        }

        return $response;
    }
}
