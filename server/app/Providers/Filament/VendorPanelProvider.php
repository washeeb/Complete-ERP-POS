<?php

namespace App\Providers\Filament;

use App\Http\Middleware\BlockSiteCopiers;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * SyncLytics' licence generator at /vendor: customers, plans, licences, invoices and the check-in dashboard. Registered only
 * on the licence server (LICENSE_SERVER=true) and left out of every client build (release:build). Everyone must use an
 * authenticator app.
 */
class VendorPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/vendor.php')); // certificate, invoice print, licence register (CSV)
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('vendor')
            ->path('vendor')
            ->brandName('SyncLytics · Licences')
            ->login()
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()])
            ->requiresMultiFactorAuthentication()
            ->colors(['primary' => Color::Indigo])
            ->darkMode()
            ->databaseNotifications()
            ->discoverResources(in: app_path('Filament/Vendor/Resources'), for: 'App\Filament\Vendor\Resources')
            ->discoverPages(in: app_path('Filament/Vendor/Pages'), for: 'App\Filament\Vendor\Pages')
            ->discoverWidgets(in: app_path('Filament/Vendor/Widgets'), for: 'App\Filament\Vendor\Widgets')
            ->navigationGroups(['Licences', 'Billing', 'Set-up'])
            ->unsavedChangesAlerts() // leaving a form with changes not saved asks first (owner request 2026-10-24)
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                BlockSiteCopiers::class, // website copiers refused, crawling slowed (after the session, to know who is signed in)
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class]);
    }
}
