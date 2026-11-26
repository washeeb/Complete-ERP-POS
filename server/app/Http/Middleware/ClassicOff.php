<?php

namespace App\Http\Middleware;

use App\Support\OfficeUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The current back office (/admin) is switched off (owner 2026-11-26, part 43): every page of it opens its page in the
 * new back office instead — a saved link, a bookmark or a phone's shortcut keeps working. Its code stays, unreachable,
 * because the new back office reuses its queries and permissions. `erp.classic_back_office` (ERP_CLASSIC_BACK_OFFICE)
 * opens it again; the tests of that code do so.
 */
class ClassicOff
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('erp.classic_back_office') || ! $request->isMethodSafe()) {
            return $next($request); // switched on, or signing out (POST)
        }
        $q = $request->getQueryString();

        return redirect()->away(rtrim(url('/'), '/').(OfficeUrl::from('/'.ltrim($request->path(), '/').($q ? "?{$q}" : '')) ?? '/office/'));
    }
}
