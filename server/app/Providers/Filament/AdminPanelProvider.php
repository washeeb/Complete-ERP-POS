<?php

namespace App\Providers\Filament;

use App\Domain\Licensing\Watermark;
use App\Filament\Auth\EditProfile;
use App\Http\Middleware\EnforceLicense;
use App\Http\Middleware\RedirectToSetupWizard;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('Synclytics ERP')
            ->login()
            ->profile(EditProfile::class, isSimple: false)
            // Authenticator app with recovery codes. Who must use it is decided per person by
            // the erp.mfa middleware (security level ≥ the configured threshold), not panel-wide.
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()])
            ->requiresMultiFactorAuthentication()
            ->multiFactorAuthenticationRequiredMiddlewareName('erp.mfa')
            ->colors(['primary' => Color::Slate])
            ->darkMode()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->databaseNotifications(false)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([Dashboard::class])
            ->navigationGroups(['Sales', 'Online', 'Purchasing', 'Finance', 'HR & payroll', 'Stock', 'Restaurant', 'Pharmacy', 'Catalog', 'Pricing & tax', 'Company', 'People & access', 'System'])
            ->widgets([AccountWidget::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Passkeys (spatie/laravel-passkeys): browser library vendored in public/, sign-in button on
            // the login page, management on the profile page.
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => new HtmlString(
                '<script src="'.asset('vendor/simplewebauthn/browser-13.3.0.umd.min.js').'"></script>'
                .'<script>window.startRegistration=SimpleWebAuthnBrowser.startRegistration;'
                .'window.startAuthentication=SimpleWebAuthnBrowser.startAuthentication;</script>'))
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn () => Blade::render(
                '<x-authenticate-passkey><x-filament::button color="gray" icon="heroicon-o-finger-print" class="w-full mt-2">Sign in with a passkey</x-filament::button></x-authenticate-passkey>'))
            ->renderHook(PanelsRenderHook::PAGE_END, fn () => Blade::render('@livewire(\'passkeys\')'), scopes: EditProfile::class)
            ->renderHook(PanelsRenderHook::BODY_START, fn () => view('license.banner'))
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => new HtmlString(Watermark::meta()))
            ->authMiddleware([
                Authenticate::class,
                EnforceLicense::class,
                RedirectToSetupWizard::class,
            ]);
    }
}
