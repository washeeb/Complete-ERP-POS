<?php

namespace App\Http\Middleware;

use App\Filament\Pages\SetupWizard;
use App\Filament\Support\Erp;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Until the company is set up, people who can run the wizard are sent to it. */
class RedirectToSetupWizard
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Erp::user();
        if (! $user || $request->routeIs('filament.admin.pages.setup-wizard', 'filament.admin.auth.*', 'livewire.*')) {
            return $next($request);
        }
        $tenant = Tenant::find($user->tenant_id);
        if ($tenant && ! $tenant->isSetUp() && Erp::can('setup.company.manage')) {
            return redirect(SetupWizard::getUrl());
        }

        return $next($request);
    }
}
