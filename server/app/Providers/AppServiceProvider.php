<?php

namespace App\Providers;

use App\Domain\Sync\Transport\HttpSyncTransport;
use App\Domain\Sync\Transport\SyncTransport;
use App\Filament\Support\TableExport;
use App\Http\Middleware\RecordSlowRequests;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Security\Authorizer;
use App\Support\Settings;
use App\Support\TenantContext;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Table;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RecordSlowRequests::class); // terminate() reads what handle() measured
        $this->app->singleton(ModuleRegistry::class, fn () => new ModuleRegistry(config('erp.modules_path')));
        // Request-scoped: memoised grants and settings never leak between requests or queue jobs.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(Authorizer::class);
        $this->app->scoped(Settings::class);
        $this->app->bind(SyncTransport::class, HttpSyncTransport::class);
    }

    public function boot(): void
    {
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
