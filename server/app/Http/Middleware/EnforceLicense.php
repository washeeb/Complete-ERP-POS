<?php

namespace App\Http\Middleware;

use App\Domain\Licensing\LicenseManager;
use App\Filament\Pages\LicensePage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * panel  — until the installation is activated, everyone signed in to the back office is sent to the licence page.
 * output — printing and downloads stop when the licence is read-only (owner decision 2026-10-15: no output at all).
 */
class EnforceLicense
{
    public function handle(Request $request, Closure $next, string $mode = 'panel'): Response
    {
        $license = app(LicenseManager::class);
        if ($mode === 'output') {
            if (! $license->canOutput()) {
                $s = $license->status();

                return response()->view('license.blocked', ['message' => $s['message']], 423);
            }

            return $next($request);
        }
        if ($request->routeIs('filament.admin.pages.licence', 'filament.admin.auth.*', 'livewire.*')) {
            return $next($request);
        }
        if ($license->status()['state'] === 'unlicensed') {
            return redirect(LicensePage::getUrl());
        }

        return $next($request);
    }
}
