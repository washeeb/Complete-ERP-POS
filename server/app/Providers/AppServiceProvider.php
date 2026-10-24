<?php

namespace App\Providers;

use App\Domain\Access\SupportAccount;
use App\Domain\Modules\EnabledModules;
use App\Domain\Support\Actor;
use App\Domain\Support\AuditLog;
use App\Domain\Sync\Transport\HttpSyncTransport;
use App\Domain\Sync\Transport\SyncTransport;
use App\Filament\Support\TableExport;
use App\Http\Middleware\RecordSlowRequests;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Providers\Filament\VendorPanelProvider;
use App\Security\Authorizer;
use App\Support\People;
use App\Support\RequestMemo;
use App\Support\Settings;
use App\Support\TenantContext;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // SyncLytics' licence generator (/vendor): only on the licence server, and its files are left out of client builds.
        if (config('license.server.enabled') && class_exists(VendorPanelProvider::class)) {
            $this->app->register(VendorPanelProvider::class);
        }
        $this->app->singleton(RecordSlowRequests::class); // terminate() reads what handle() measured
        $this->app->singleton(ModuleRegistry::class, fn () => new ModuleRegistry(config('erp.modules_path')));
        // Request-scoped: memoised grants and settings never leak between requests or queue jobs.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(Authorizer::class);
        $this->app->scoped(Settings::class);
        $this->app->scoped(EnabledModules::class);
        $this->app->scoped(RequestMemo::class);
        $this->app->bind(SyncTransport::class, HttpSyncTransport::class);
    }

    public function boot(): void
    {
        // Names and codes in capitals (owner decision 2026-10-22): shown as typed in capitals; the services store them so.
        TextInput::macro('caps', function () {
            /** @var TextInput $this */
            return $this->extraInputAttributes(['style' => 'text-transform: uppercase']);
        });
        // Who did it: the name at the time, as recorded (also on prints). If the account has been renamed since, an icon
        // and a hint give today's name; People & access → Users → Name history lists every rename.
        foreach ([TextColumn::class, TextEntry::class] as $component) {
            $component::macro('asPerson', function (string $idColumn) {
                /** @var TextColumn|TextEntry $this */
                $renamed = fn ($state, $record) => People::renamedTo($record?->{$idColumn}, $state);

                return $this->icon(fn ($state, $record) => $renamed($state, $record) ? 'heroicon-m-arrows-right-left' : null)->iconPosition('after')
                    ->tooltip(fn ($state, $record) => ($now = $renamed($state, $record)) ? "Account now named {$now}" : null);
            });
        }

        // Back-office sign-ins and sign-outs, with the computer, in the audit log (tills record theirs as pos.login).
        $signIn = function (string $action, $user) {
            if (! $user instanceof User || ! $user->tenant_id || app()->runningInConsole() && ! app()->runningUnitTests()) {
                return;
            }
            DB::transaction(fn () => app(AuditLog::class)->record(Actor::user($user, 'web'), $action, $user));
        };
        Event::listen(Login::class, function (Login $e) use ($signIn) {
            if ($e->guard === 'web') {
                $signIn('auth.signed_in', $e->user);
                if ($e->user instanceof User) {
                    app(SupportAccount::class)->signedIn($e->user); // the owner is told each time SyncLytics support signs in
                }
            }
        });
        Event::listen(Logout::class, fn (Logout $e) => $e->guard === 'web' ? $signIn('auth.signed_out', $e->user) : null);
        Event::listen(Failed::class, fn (Failed $e) => $e->guard === 'web' ? $signIn('auth.sign_in_failed', $e->user) : null);

        // Erp::run shows "Not saved" with the reason and then stops with Filament's Halt. Actions and record pages catch
        // it; a custom screen's own method (Save modules, …) would turn it into an error page, so it stops there too.
        \Livewire\on('exception', function ($component, \Throwable $e, callable $stopPropagation) {
            if ($e instanceof Halt) {
                $stopPropagation();
            }
        });

        if (! in_array(config('erp.node.role'), ['hq', 'branch', 'standalone'], true)) {
            throw new \RuntimeException('ERP_NODE_ROLE must be hq, branch or standalone.');
        }

        // Every list in the back office can be exported (CSV / PDF) with its current search, filters and sort.
        Table::configureUsing(fn (Table $table) => $table->pushHeaderActions([TableExport::group()]));

        // Dotted abilities are ERP permissions: $user->can('admin.users.manage', $branch).
        // No target = only a company-wide grant counts. Anything else falls through to policies.
        Gate::before(function ($user, string $ability, array $args) {
            if (! $user instanceof User || ! str_contains($ability, '.')) {
                return null;
            }

            return app(Authorizer::class)->allows($user, $ability, $args[0] ?? null);
        });

        RateLimiter::for('public-api', fn (Request $r) => Limit::perMinute(120)->by('api:'.(string) $r->user()?->getKey()));
        RateLimiter::for('device-enroll', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('webhooks', fn (Request $r) => Limit::perMinute(300)->by((string) $r->route('channel')));
        RateLimiter::for('node-sync', fn (Request $r) => Limit::perMinute(600)->by($r->header('X-Sync-Node').'|'.$r->ip()));
        RateLimiter::for('wms', fn (Request $r) => Limit::perMinute(300)->by((string) $r->user()?->getKey())); // scanning is bursty
        RateLimiter::for('kds', fn (Request $r) => Limit::perMinute(240)->by((string) $r->user()?->getKey())); // kitchen screens poll every few seconds
        RateLimiter::for('pos-sync', fn (Request $r) => Limit::perMinute(120)->by((string) $r->user()?->getKey()));
        RateLimiter::for('pos-login', fn (Request $r) => [
            Limit::perMinute(10)->by($r->ip()),
            Limit::perMinute(20)->by((string) $r->user()?->getKey()),
        ]);
    }
}
