<?php

namespace App\Security;

use App\Domain\Licensing\LicenseManager;
use App\Models\Branch;
use App\Models\Industry;
use App\Models\Permission;
use App\Models\RoleAssignment;
use App\Models\Till;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * allowed = active user AND unexpired, unrevoked assignment AND role level ≤ user's ceiling
 *           AND permission in role AND permission min level ≤ user's ceiling
 *           AND the assignment's scope covers the target (AND amount ≤ limit, when asked).
 * Deny by default. A missing scope is never treated as global scope.
 */
final class Authorizer
{
    /** @var array<string, Collection> per-request memo of a user's effective grants */
    private array $memo = [];

    /** Allowed on this target. With no target: only a company-wide grant counts. */
    public function allows(User $user, string $permission, ?Model $target = null): bool
    {
        $path = $target ? $this->scopePath($target) : [ScopeType::Tenant->value.':'.$user->tenant_id];

        return $this->grants($user, $permission)->contains(fn ($g) => in_array($g['scope'], $path, true));
    }

    /**
     * Held through the user's own roles on this target, whatever the licence currently switches off. Only for deciding
     * who may hand a role to someone else: granting Cashier must not fail because the licence leaves out HR (Cashier
     * includes "request leave"). What anyone can actually use is still decided by allows(), which honours the licence.
     */
    /** @param  list<string>  $permissions  true when the person holds any of them for the target */
    public function allowsAny(User $user, array $permissions, ?Model $target = null): bool
    {
        foreach ($permissions as $p) {
            if ($this->allows($user, $p, $target)) {
                return true;
            }
        }

        return false;
    }

    public function holds(User $user, string $permission, ?Model $target = null): bool
    {
        $path = $target ? $this->scopePath($target) : [ScopeType::Tenant->value.':'.$user->tenant_id];

        return $this->grants($user, $permission, honourLicence: false)->contains(fn ($g) => in_array($g['scope'], $path, true));
    }

    /** Allowed somewhere (used for navigation visibility, never for a write). */
    public function allowsAnywhere(User $user, string $permission): bool
    {
        return $this->grants($user, $permission)->isNotEmpty();
    }

    /** Highest limit across grants covering the target; null when no grant covers it. Missing limit key = 0. */
    public function limit(User $user, string $permission, Model $target, string $limitKey): ?int
    {
        $path = $this->scopePath($target);
        $covering = $this->grants($user, $permission)->filter(fn ($g) => in_array($g['scope'], $path, true));

        return $covering->isEmpty() ? null : (int) $covering->max(fn ($g) => (int) ($g['limits'][$limitKey] ?? 0));
    }

    public function allowsAmount(User $user, string $permission, Model $target, string $limitKey, int $amount): bool
    {
        $limit = $this->limit($user, $permission, $target, $limitKey);

        return $limit !== null && $amount <= $limit;
    }

    /**
     * Branch ids the user may act on for this permission: null = every branch (company-wide grant),
     * [] = none. Use to filter list queries.
     *
     * @return list<string>|null
     */
    public function branchIds(User $user, string $permission): ?array
    {
        $grants = $this->grants($user, $permission);
        if ($grants->contains(fn ($g) => $g['scope'] === ScopeType::Tenant->value.':'.$user->tenant_id)) {
            return null;
        }

        $ids = [];
        foreach ($grants as $g) {
            [$type, $id] = explode(':', $g['scope'], 2);
            $ids = array_merge($ids, match ($type) {
                ScopeType::LegalEntity->value => Branch::where('legal_entity_id', $id)->pluck('id')->all(),
                ScopeType::Industry->value => Branch::where('industry_id', $id)->pluck('id')->all(),
                ScopeType::Branch->value => [$id],
                // Narrower scopes don't grant the whole branch.
                default => [],
            });
        }

        return array_values(array_unique($ids));
    }

    public function forget(?User $user = null): void
    {
        if ($user) {
            unset($this->memo[$user->id]);
        } else {
            $this->memo = [];
        }
        $this->permissions = [];
    }

    /** @var array<string, ?Permission> */
    private array $permissions = [];

    /** @return Collection<int, array{scope: string, limits: array}> */
    private function grants(User $user, string $permission, bool $honourLicence = true): Collection
    {
        if (! $user->is_active) {
            return collect();
        }
        if ($honourLicence && ! $this->licensed($permission)) {
            return collect(); // the licence does not include this part of the system
        }
        $all = $this->memo[$user->id] ??= $this->loadGrants($user);
        $perm = array_key_exists($permission, $this->permissions)
            ? $this->permissions[$permission]
            : ($this->permissions[$permission] = Permission::find($permission));
        if (! $perm || $perm->min_level > $user->security_level) {
            return collect();
        }

        return $all->filter(fn ($g) => in_array($permission, $g['permissions'], true))->values();
    }

    /** Licensed parts of the system, by permission prefix (owner decision 2026-10-15: modules are licensed). */
    public const FEATURES = [
        'finance' => ['finance.', 'payables.', 'banking.', 'expenses.', 'assets.', 'budgets.'],
        'hr' => ['hr.', 'attendance.', 'leave.', 'loans.', 'payroll.'],
    ];

    private function licensed(string $permission): bool
    {
        foreach (self::FEATURES as $feature => $prefixes) {
            foreach ($prefixes as $p) {
                if (str_starts_with($permission, $p)) {
                    return app(LicenseManager::class)->allowsFeature($feature);
                }
            }
        }

        return true;
    }

    private function loadGrants(User $user): Collection
    {
        return RoleAssignment::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->active()
            ->with('role.permissions')
            ->get()
            ->filter(fn (RoleAssignment $a) => $a->role && $a->role->tenant_id === $user->tenant_id
                && $a->role->security_level <= $user->security_level)
            ->map(fn (RoleAssignment $a) => [
                'scope' => $a->scope_type->value.':'.$a->scope_id,
                'limits' => $a->limits ?? [],
                'permissions' => $a->role->permissions->pluck('key')->all(),
            ])
            ->values();
    }

    /**
     * Every scope that contains the target, from company down to the object itself.
     *
     * @return list<string>
     */
    public function scopePath(Model $target): array
    {
        $tenant = ScopeType::Tenant->value.':'.$target->getAttribute('tenant_id');
        $branchPath = function (?string $branchId) use ($tenant): array {
            $branch = $branchId ? Branch::withoutGlobalScopes()->find($branchId) : null;
            if (! $branch) {
                return [$tenant];
            }

            return array_values(array_filter([
                $tenant,
                $branch->legal_entity_id ? ScopeType::LegalEntity->value.':'.$branch->legal_entity_id : null,
                $branch->industry_id ? ScopeType::Industry->value.':'.$branch->industry_id : null,
                ScopeType::Branch->value.':'.$branch->id,
            ]));
        };

        return match (true) {
            $target instanceof Branch => $branchPath($target->id),
            $target instanceof Industry => [$tenant, ScopeType::Industry->value.':'.$target->id],
            $target instanceof Warehouse => [...$branchPath($target->branch_id), ScopeType::Warehouse->value.':'.$target->id],
            $target instanceof Till => [...$branchPath($target->branch_id),
                ScopeType::Warehouse->value.':'.$target->warehouse_id, ScopeType::Till->value.':'.$target->id],
            $target->getAttribute('branch_id') !== null => $branchPath($target->getAttribute('branch_id')),
            default => [$tenant],
        };
    }
}
