<?php

namespace App\Providers\Filament;

use App\Domain\Licensing\Watermark;
use App\Filament\Auth\EditProfile;
use App\Filament\Auth\Login;
use App\Filament\Support\PanelBrand;
use App\Filament\Widgets\BusinessStats;
use App\Filament\Widgets\IndustryHighlights;
use App\Filament\Widgets\IndustryOverview;
use App\Http\Middleware\BlockSiteCopiers;
use App\Http\Middleware\EnforceLicense;
use App\Http\Middleware\RedirectToSetupWizard;
use App\Http\Middleware\TrackComputer;
use App\Security\SignInCode;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
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
            // Client brand first (Company → Branding), the product's until a company has its own.
            ->brandName(fn () => PanelBrand::name())
            ->brandLogo(fn () => PanelBrand::logo())
            ->darkModeBrandLogo(fn () => PanelBrand::darkLogo())
            ->brandLogoHeight('2.25rem')
            ->favicon(fn () => PanelBrand::favicon())
            ->login(Login::class)
            ->profile(EditProfile::class, isSimple: false)
            // Authenticator app with recovery codes. Who must use it is decided per person by
            // the erp.mfa middleware (security level ≥ the configured threshold), not panel-wide.
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable(), SignInCode::make()])
            ->requiresMultiFactorAuthentication()
            ->multiFactorAuthenticationRequiredMiddlewareName('erp.mfa')
            ->colors(fn () => PanelBrand::colors())
            ->darkMode()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->databaseNotifications()->databaseNotificationsPolling('30s')
            // Pages refresh parts of themselves in the background, so a dropped connection or an idle sign-out shows up
            // even when nobody is clicking: say which it was and what to do, instead of Filament's "Error while loading page".
            ->registerErrorNotification('Could not reach the server', 'The connection to the server dropped for a moment (internet or network). '
                .'The page carries on when it is back; if this keeps happening, check the internet connection, or tell SyncLytics support.')
            ->registerErrorNotification('You were signed out', 'After some time without use you are signed out, for safety. Reload the page (F5) to sign in again.', 419)
            ->registerErrorNotification('The server is being updated', 'Try again in a minute.', 503)
            ->registerErrorNotification('Something went wrong on the server', 'It has been noted in the server log. Try again; if it happens again, tell SyncLytics support what you were doing.', 500)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([Dashboard::class])
            ->navigationGroups(['Insights', 'Reports', 'Sales', 'Cash', 'Online', 'Purchasing', 'Finance', 'HR & payroll', 'Stock', 'Grocery', 'Restaurant', 'Pharmacy', 'Electronics', 'Fashion', 'Catalog', 'Pricing & tax', 'Company', 'People & access', 'Messages', 'System'])
            ->widgets([AccountWidget::class, BusinessStats::class, IndustryOverview::class, IndustryHighlights::class])
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
            ->renderHook(PanelsRenderHook::BODY_START, fn () => view('launch.practice-banner'))
            ->renderHook(PanelsRenderHook::BODY_START, fn () => view('remote.support-banner'))
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('progress.overlay')) // "Importing 150 of 2,000" for long work
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('layout.sidebar-focus')) // the current menu section at the top
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_START, fn () => view('layout.menu-search')) // type to find a page in the menu
            ->renderHook(PanelsRenderHook::FOOTER, fn () => view('branding.powered-by'))
            // "Report a problem" for everyone signed in (pilot support, Phase 14).
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn () => Blade::render('@livewire(\'report-problem\')'))
            // Industries: show one industry (its branches, catalogue, stock, sales, reports) or all.
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE, fn () => Blade::render('@livewire(\'industry-switcher\')'))
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER, fn () => view('filament.calculator')) // calculator for everyone in the top bar
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => new HtmlString(Watermark::meta()))
            // Where am I (current menu item, group and tab stand out) and file pickers that look like buttons.
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => new HtmlString('<link rel="stylesheet" href="'.asset('css/seslabs/admin.css').'?v='.@filemtime(public_path('css/seslabs/admin.css')).'">'))
            // Wide lists: a side-to-side scrollbar pinned to the window while the list's own is out of sight (one scroll up and down).
            ->renderHook(PanelsRenderHook::BODY_END, fn () => new HtmlString('<script src="'.asset('js/seslabs/hscroll.js').'?v='.@filemtime(public_path('js/seslabs/hscroll.js')).'" defer></script>'))
            // "Add to Home screen" on a phone opens Today (the owner's phone view) like an app.
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => new HtmlString('<link rel="manifest" href="'.asset('admin.webmanifest').'"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><link rel="apple-touch-icon" href="'.asset('pos/icon-192.png').'">'))
            ->middleware([TrackComputer::class], isPersistent: true) // which computer: System → Computers, the audit log
            ->authMiddleware([
                Authenticate::class,
                EnforceLicense::class,
                RedirectToSetupWizard::class,
            ]);
    }
}
