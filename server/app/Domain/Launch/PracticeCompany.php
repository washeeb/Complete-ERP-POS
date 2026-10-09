<?php

namespace App\Domain\Launch;

use App\Domain\Customers\CustomerService;
use App\Domain\Imports\ImportService;
use App\Domain\Licensing\LicenseManager;
use App\Domain\Modules\ModuleService;
use App\Domain\Org\OrgService;
use App\Domain\Org\TenantBaseline;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Security\AccessCatalogSync;
use App\Security\Authorizer;
use App\Security\ScopeType;
use App\Support\Money;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The practice company (Phase 14, training): a second company on the same server, copied from the real one — branches
 * and tills, settings, modules, products, prices and the stock on hand — with made-up customers. Chosen staff get a
 * practice login: the same name, username, password, PIN and two-step code, and the e-mail address with "+practice"
 * (kofi+practice@shop.com). Nothing a practice company does is sent anywhere (GRA, SMS/WhatsApp/e-mail, webhooks,
 * online stores); its screens and receipts say PRACTICE. Its data never mixes with the real books.
 */
final class PracticeCompany
{
    /** Settings that are never copied (they talk to the outside world, or belong to the real company only). */
    private const SKIP_SETTINGS = ['evat.', 'ai.', 'messaging.', 'channels.', 'license.'];

    private const SAMPLE_CUSTOMERS = ['Ama Practice', 'Kofi Practice', 'Esi Practice', 'Yaw Practice', 'Akosua Practice', 'Kwame Practice',
        'Abena Practice', 'Kojo Practice', 'Practice Provisions Ltd', 'Practice Chop Bar'];

    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly AccessCatalogSync $catalog,
        private readonly TenantBaseline $baseline,
        private readonly OrgService $org,
        private readonly ModuleService $modules,
        private readonly CustomerService $customers,
    ) {}

    /** The practice company made from this real company, if any (archived ones are not counted). */
    public static function of(string $tenantId): ?Tenant
    {
        return Tenant::where('practice_of', $tenantId)->where('is_practice', true)->where('name', 'not like', '%(archived)')->orderByDesc('created_at')->first();
    }

    /**
     * @param  list<string>  $userIds  staff (of the real company) who get a practice login; the person creating it always does
     * @return array{tenant: Tenant, logins: list<array{name: string, username: string, email: string}>, copied: array<string, int>, problems: list<string>}
     */
    public function create(Actor $actor, array $userIds = [], bool $copyCatalogue = true): array
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'golive.manage')) {
            throw new AccessDenied;
        }
        $real = Tenant::findOrFail($actor->tenantId);
        if ($real->is_practice) {
            throw new DomainException('This already is a practice company.');
        }
        if (! $real->isSetUp()) {
            throw new DomainException('Finish the setup wizard of the real company first: the practice company copies its branches and tills.');
        }
        if ($existing = self::of($real->id)) {
            throw new DomainException("There already is a practice company ({$existing->name}). Archive it first to start a fresh one.");
        }
        $context = app(TenantContext::class);
        $realId = $real->id;
        $snapshot = $this->read($realId, array_values(array_unique([$actor->user->id, ...$userIds])), $copyCatalogue);

        try {
            return LicenseManager::bypass(function () use ($actor, $real, $snapshot, $context) {
                $newPermissions = $this->catalog->syncPermissions();
                [$tenant, $owner, $logins] = $this->recorder->transaction(function () use ($actor, $real, $snapshot, $newPermissions, $context) {
                    $tenant = new Tenant(['name' => mb_substr($real->name, 0, 200).' — PRACTICE', 'slug' => $this->slug($real->slug.'-practice'),
                        'country_code' => $real->country_code, 'currency_code' => $real->currency_code, 'locale' => $real->locale, 'timezone' => $real->timezone]);
                    $tenant->forceFill(['is_practice' => true, 'practice_of' => $real->id]);
                    $tenant->save();
                    Tenant::forgetPractice();
                    $context->set($tenant->id);
                    $system = Actor::system($tenant->id, "Practice company set up by {$actor->name}");
                    $this->recorder->audit->record($system, 'tenant.created', $tenant, ['name' => [null, $tenant->name], 'practice_of' => [null, $real->name]]);
                    $this->recorder->outbox->publish($system, $tenant->fresh(), 'tenant.created');
                    $this->catalog->syncRoles($tenant->id, $newPermissions);
                    $this->baseline->apply($tenant);

                    // Logins first: the practice owner (the person setting it up) does the rest under their own name.
                    $logins = [];
                    $users = [];
                    foreach ($snapshot['users'] as $u) {
                        $copy = new User;
                        $copy->forceFill(['tenant_id' => $tenant->id, 'name' => $u->name, 'username' => $u->username, 'email' => $this->practiceEmail($u->email),
                            'password' => Str::random(40), 'security_level' => $u->security_level, 'is_active' => true]);
                        $this->recorder->save($system, $copy, 'user.created', 'Practice login copied from the real company');
                        DB::table('users')->where('id', $copy->id)->update(['password' => $u->password, 'pin_hash' => $u->pin_hash,
                            'app_authentication_secret' => $u->app_authentication_secret, 'app_authentication_recovery_codes' => $u->app_authentication_recovery_codes]);
                        $users[$u->id] = $copy;
                        $logins[] = ['name' => $u->name, 'username' => $u->username, 'email' => $copy->email];
                    }
                    $owner = $users[$actor->user->id];
                    $ownerRole = Role::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('key', 'owner')->firstOrFail();
                    $this->grant($system, $owner, $ownerRole, ScopeType::Tenant, $tenant->id, 'Practice company owner');

                    $ownerActor = Actor::user($owner->fresh(), 'system');
                    $this->org->completeSetup($ownerActor, ['deployment_mode' => $real->deployment_mode ?: 'single', 'company' => ['name' => $tenant->name],
                        'branches' => array_map(fn ($b) => ['code' => $b->code, 'name' => $b->name, 'is_hq' => (bool) $b->is_hq, 'tills' => $b->tills], $snapshot['branches'])]);
                    $branches = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get()->keyBy('code');
                    foreach ($snapshot['modules'] as $m) {
                        if (isset($branches[$m->code]) && $m->module_key !== 'core') {
                            try {
                                $this->modules->setStatus($ownerActor, $branches[$m->code], $m->module_key, ModuleService::ENABLED, 'Copied for the practice company', bootstrap: true);
                            } catch (Throwable) {
                                // a module that cannot be switched on here simply stays off in practice
                            }
                        }
                    }
                    foreach ($snapshot['settings'] as $s) {
                        DB::table('settings')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'key' => $s->key, 'value' => $s->value,
                            'updated_by' => $owner->id, 'version' => 1, 'origin_node' => (string) config('erp.node.id'), 'created_at' => now(), 'updated_at' => now()]);
                    }
                    $roles = Role::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get()->keyBy('key');
                    foreach ($snapshot['grants'] as $g) {
                        $user = $users[$g->user_id] ?? null;
                        $role = $roles[$g->role_key] ?? null;
                        if (! $user || ! $role || ($user->id === $owner->id && $g->role_key === 'owner')) {
                            continue;
                        }
                        [$type, $scopeId] = match ($g->scope_type) {
                            ScopeType::Tenant->value => [ScopeType::Tenant, $tenant->id],
                            ScopeType::Branch->value => [ScopeType::Branch, $branches[$g->branch_code ?? '']?->id ?? null],
                            default => [null, null],
                        };
                        if ($type && $scopeId) {
                            $this->grant($system, $user, $role, $type, $scopeId, 'Copied from the real company for practice', $g->limits);
                        }
                    }
                    $this->recorder->audit->record(Actor::system($real->id, $actor->name), 'practice.created', $tenant, ['name' => [null, $tenant->name],
                        'logins' => [null, count($logins)]]);

                    return [$tenant, $owner, $logins];
                });

                // The catalogue goes in row by row through the normal imports (each row its own transaction), as the practice owner.
                $context->set($tenant->id);
                $ownerActor = Actor::user($owner->fresh(), 'system');
                $copied = ['products' => 0, 'prices' => 0, 'stock' => 0, 'customers' => 0];
                $problems = [];
                $apply = function (string $type, array $rows, string $count) use ($ownerActor, &$copied, &$problems) {
                    $importer = ImportService::importer($type);
                    $options = $importer->optionDefaults();
                    foreach ($rows as $row) {
                        try {
                            DB::transaction(fn () => $importer->apply($ownerActor, $row, $options, null));
                            $copied[$count]++;
                        } catch (Throwable $e) {
                            if (count($problems) < 20) {
                                $problems[] = "{$importer->label()} ".($row['sku'] ?? '').": {$e->getMessage()}";
                            }
                        }
                    }
                };
                if ($snapshot['products']) {
                    $apply('products', $snapshot['products'], 'products');
                    $apply('prices', $snapshot['prices'], 'prices');
                    $apply('opening_stock', $snapshot['stock'], 'stock');
                } else {
                    Artisan::call('erp:demo-catalog', ['--as' => $owner->email, '--no-interaction' => true]); // nothing to copy yet: the sample range
                    $copied['products'] = DB::table('products')->where('tenant_id', $tenant->id)->count();
                }
                foreach (self::SAMPLE_CUSTOMERS as $name) {
                    try {
                        DB::transaction(fn () => $this->customers->save($ownerActor, new Customer, ['name' => $name], 'Practice company (made up)'));
                        $copied['customers']++;
                    } catch (Throwable $e) {
                        $problems[] = "Customer {$name}: {$e->getMessage()}";
                    }
                }

                return ['tenant' => $tenant->fresh(), 'logins' => $logins, 'copied' => $copied, 'problems' => $problems];
            });
        } finally {
            $context->set($realId);
        }
    }

    /** Archive: practice logins stop working; the practice data stays (ledgers are never deleted). */
    public function archive(Actor $actor, Tenant $practice): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'golive.manage')) {
            throw new AccessDenied;
        }
        if (! $practice->is_practice || $practice->practice_of !== $actor->tenantId) {
            throw new DomainException('That is not this company’s practice company.');
        }
        $this->recorder->transaction(function () use ($actor, $practice) {
            DB::table('users')->where('tenant_id', $practice->id)->update(['is_active' => false]);
            $practice->forceFill(['name' => mb_substr($practice->name, 0, 230).' (archived)'])->save();
            $this->recorder->audit->record($actor, 'practice.archived', $practice, ['name' => [null, $practice->name]]);
        });
    }

    /** What is copied, read under the real company before anything is written. */
    private function read(string $tenantId, array $userIds, bool $copyCatalogue): array
    {
        $users = DB::table('users')->where('tenant_id', $tenantId)->whereIn('id', $userIds)->where('is_active', true)->get()->all();
        $grants = DB::table('role_assignments as a')->join('roles as r', 'r.id', '=', 'a.role_id')->leftJoin('branches as b', 'b.id', '=', 'a.scope_id')
            ->where('a.tenant_id', $tenantId)->whereIn('a.user_id', $userIds)->whereNull('a.revoked_at')
            ->where(fn ($q) => $q->whereNull('a.expires_at')->orWhere('a.expires_at', '>', now()))
            ->get(['a.user_id', 'r.key as role_key', 'a.scope_type', 'b.code as branch_code', 'a.limits'])
            ->map(function ($g) {
                $g->limits = is_string($g->limits) ? (json_decode($g->limits, true) ?: []) : (array) $g->limits;

                return $g;
            })->all();
        $branches = DB::table('branches')->where('tenant_id', $tenantId)->where('is_active', true)->orderByDesc('is_hq')->orderBy('code')->get(['id', 'code', 'name', 'is_hq'])
            ->map(function ($b) {
                $b->tills = min(50, DB::table('tills')->where('branch_id', $b->id)->count());

                return $b;
            })->all();
        $modules = DB::table('branch_modules as m')->join('branches as b', 'b.id', '=', 'm.branch_id')->where('b.tenant_id', $tenantId)
            ->where('m.status', ModuleService::ENABLED)->get(['b.code', 'm.module_key'])->all();
        $settings = DB::table('settings')->where('tenant_id', $tenantId)->get(['key', 'value'])
            ->reject(fn ($s) => Str::startsWith($s->key, self::SKIP_SETTINGS))->all();

        $products = $prices = $stock = [];
        if ($copyCatalogue) {
            $actor = Actor::system($tenantId, 'Practice copy');
            $read = function (string $type) use ($actor) {
                $importer = ImportService::importer($type);
                $heads = $importer->headings();
                $out = [];
                foreach ($importer->exportRows($actor, $importer->optionDefaults(), null) ?? [] as $values) {
                    $out[] = array_combine($heads, array_map(fn ($v) => (string) $v, array_pad(array_slice(array_values((array) $values), 0, count($heads)), count($heads), '')));
                }

                return $out;
            };
            $products = $read('products');
            $prices = $read('prices');
            $exp = Money::exponent((string) Tenant::find($tenantId)->currency_code);
            $stock = DB::table('stock_levels as l')->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')->join('products as p', 'p.id', '=', 'l.product_id')
                ->where('l.tenant_id', $tenantId)->where('l.on_hand', '>', 0)->where('w.code', 'like', '%-W1')
                ->get(['w.code as warehouse', 'p.sku', 'l.on_hand', 'l.avg_cost_minor'])
                ->map(fn ($r) => ['warehouse' => $r->warehouse, 'sku' => $r->sku, 'qty' => (string) $r->on_hand,
                    'unit_cost' => $r->avg_cost_minor === null ? '' : number_format((float) $r->avg_cost_minor / (10 ** $exp), $exp, '.', '')])->all(); // practice: cost to the pesewa is enough
        }

        return compact('users', 'grants', 'branches', 'modules', 'settings', 'products', 'prices', 'stock');
    }

    private function grant(Actor $system, User $user, Role $role, ScopeType $type, string $scopeId, string $reason, array $limits = []): void
    {
        $grant = new RoleAssignment(['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => $type, 'scope_id' => $scopeId, 'starts_at' => now(),
            'reason' => $reason, 'limits' => $limits ?: ($role->default_limits ?? [])]);
        $grant->tenant_id = $user->tenant_id;
        $grant->granted_by = $user->id; // practice set-up: no human granter in this company yet; the audit row names who set it up
        $grant->granted_by_name = $system->name;
        $this->recorder->save($system, $grant, 'access.granted', $reason);
    }

    /** kofi@shop.com → kofi+practice@shop.com (unique on this server). */
    private function practiceEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', strtolower($email), 2), 2, 'practice.invalid');
        $base = Str::before($local, '+').'+practice';
        $candidate = "{$base}@{$domain}";
        for ($i = 2; User::withoutGlobalScopes()->where('email', $candidate)->exists(); $i++) {
            $candidate = "{$base}{$i}@{$domain}";
        }

        return $candidate;
    }

    private function slug(string $base): string
    {
        $base = Str::slug($base) ?: 'practice';
        $slug = $base;
        for ($i = 2; Tenant::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
