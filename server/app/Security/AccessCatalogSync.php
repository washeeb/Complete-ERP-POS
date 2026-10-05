<?php

namespace App\Security;

use App\Models\Permission;
use App\Models\Role;
use App\Modules\ModuleRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Runs on every deploy (seeder) and at install. Adds what is missing, refreshes structure
 * (labels, levels), never overwrites a human decision:
 *  - a new permission is attached to its default roles once, when it is first created;
 *  - a permission removed from a role in the admin is never re-attached;
 *  - existing role names/levels edited by the owner are left alone.
 */
final class AccessCatalogSync
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function syncPermissions(): array
    {
        $created = [];
        foreach ($this->modules->permissions() as $p) {
            $perm = Permission::find($p['key']) ?? new Permission(['key' => $p['key']]);
            $isNew = ! $perm->exists;
            $perm->fill($p)->save();
            if ($isNew) {
                $created[] = $p['key'];
            }
        }

        return $created;
    }

    /** @param list<string> $newPermissionKeys permissions created in this run */
    public function syncRoles(string $tenantId, array $newPermissionKeys): void
    {
        $all = Permission::all(['key', 'is_technical'])->map(fn ($p) => ['key' => $p->key, 'is_technical' => $p->is_technical])->all();

        DB::transaction(function () use ($tenantId, $newPermissionKeys, $all) {
            foreach (RoleCatalog::ROLES as $key => [$name, $level, $technical]) {
                $role = Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('key', $key)->first();
                $isNewRole = $role === null;
                if ($isNewRole) {
                    $role = new Role(['key' => $key, 'name' => $name, 'security_level' => $level, 'is_technical' => $technical]);
                    $role->tenant_id = $tenantId;
                    $role->is_system = true;
                    $role->save();
                }
                $defaults = RoleCatalog::defaultsFor($key, $all);
                // A brand-new role gets all its defaults; an existing one only gets newly introduced permissions.
                $attach = $isNewRole ? $defaults : array_values(array_intersect($defaults, $newPermissionKeys));
                if ($attach) {
                    $role->permissions()->syncWithoutDetaching($attach);
                }
            }
        });
    }
}
