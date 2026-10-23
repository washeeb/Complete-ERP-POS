<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps website copiers out (owner request 2026-10-23): tools such as HTTrack, Cyotek WebCopy, Offline Explorer or
 * wget that try to download the whole site are refused by name, and anyone not signed in who asks for pages faster
 * than a person could (crawling) is slowed down. The web server refuses the same tools before PHP (installers'
 * Caddy and nginx settings use the same list). A copier that hides its name gets only the sign-in page and the till's
 * minified shell anyway — the system's code, data and licence never leave the server.
 */
class BlockSiteCopiers
{
    /** The same list as in installer/ubuntu/install.sh, installer/windows/install.ps1 and installer/docker/nginx.conf. */
    public const AGENTS = '/httrack|webcopier|webcopy|offline ?explorer|teleport ?pro|sitesucker|webzip|webreaper|website ?downloader|wget|webstripper|websitecopier|getright|a1 website|scrapy|grab-site|archivebox|heritrix|wpull/i';

    /** Page requests a minute from one address, not signed in, before it is slowed down. */
    public const GUEST_PER_MINUTE = 120;

    public function handle(Request $request, Closure $next): Response
    {
        if (preg_match(self::AGENTS, (string) $request->userAgent())) {
            return response('Copying this site is not allowed.', 403, ['Content-Type' => 'text/plain', 'X-Robots-Tag' => 'noindex, nofollow, noarchive']);
        }
        // Signed-in people, tills and integrations (API with their own tokens and limits) are never slowed down here.
        if (! $request->is('api/*', 'up', 'broadcasting/*') && ! $request->user() && ! $request->bearerToken()) {
            $key = 'guest-pages:'.$request->ip();
            if (RateLimiter::tooManyAttempts($key, self::GUEST_PER_MINUTE)) {
                return response('Too many requests. Wait a minute and try again.', 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
            }
            RateLimiter::hit($key, 60);
        }

        return $next($request);
    }
}
