<?php

namespace Database\Seeders;

use App\Domain\Org\TenantBaseline;
use App\Models\Tenant;
use App\Security\AccessCatalogSync;
use Illuminate\Database\Seeder;

/**
 * Safe on every deploy: adds new permissions and missing system roles, never overwrites
 * or restores anything an administrator changed. Companies are created by erp:install.
 */
class DatabaseSeeder extends Seeder
{
    public function run(AccessCatalogSync $catalog, TenantBaseline $baseline): void
    {
        $new = $catalog->syncPermissions(); // permissions are code (same on every node)
        if (config('erp.node.role') === 'branch') {
            return; // roles and starter data are head-office data: they arrive by sync
        }
        foreach (Tenant::all() as $tenant) {
            $catalog->syncRoles($tenant->id, $new);
            $baseline->apply($tenant);
        }
    }
}
