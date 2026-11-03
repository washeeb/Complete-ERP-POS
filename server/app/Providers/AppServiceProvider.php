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
use App\Http\Responses\HomeLoginResponse;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Providers\Filament\VendorPanelProvider;
use App\Security\Authorizer;
use App\Support\People;
use App\Support\RequestMemo;
use App\Support\Settings;
use App\Support\TenantContext;
use App\Support\UserPrefs;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Console\ServeCommand;
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
        $this->app->bind(LoginResponse::class, HomeLoginResponse::class); // after signing in: the person's own home page
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
        // `php artisan serve` (development) hands the PHP it starts only a short list of settings; on Windows, without
        // TMP/TEMP that PHP has no temporary folder and every file upload fails ("unable to create a temporary file").
        ServeCommand::$passthroughVariables = array_values(array_unique([...ServeCommand::$passthroughVariables, 'TMP', 'TEMP']));
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

        // Each person's preferences (Profile → Preferences): rows a list shows at first, and how dates are written in
        // lists and record pages. Lists with their own sizes (e.g. the chart of accounts) keep them.
        Table::configureUsing(fn (Table $table) => $table->paginated(UserPrefs::ROWS)->defaultPaginationPageOption(fn () => UserPrefs::get('rows'))
            ->defaultDateDisplayFormat(fn () => UserPrefs::dateFormat())->defaultDateTimeDisplayFormat(fn () => UserPrefs::dateTimeFormat()));
        Schema::configureUsing(fn (Schema $schema) => $schema->defaultDateDisplayFormat(fn () => UserPrefs::dateFormat())->defaultDateTimeDisplayFormat(fn () => UserPrefs::dateTimeFormat()));

        // Filament shows "New" and "Edit" buttons to everyone unless told otherwise, so a person could see a button that
        // only leads to "no access". On a resource's own pages they follow the resource's rules (canCreate / canEdit),
        // the same ones its create and edit pages enforce. Relation managers and custom pages keep their own rules.
        $resourceOf = fn (Action $action): ?string => ($page = $action->getLivewire()) instanceof ResourcePage ? $page::getResource() : null;
        CreateAction::configureUsing(fn (CreateAction $a) => $a->authorize(fn (CreateAction $action): bool => ($r = $resourceOf($action)) === null || $r::canCreate()));
        EditAction::configureUsing(fn (EditAction $a) => $a->authorize(fn (EditAction $action, ?Model $record): bool => ($r = $resourceOf($action)) === null || $record === null || $r::canEdit($record)));

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
        RateLimiter::for('iot', fn (Request $r) => Limit::perMinute(30)->by((string) $r->route('token'))); // a sensor reports every minute or few; more is a fault or an attack
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
