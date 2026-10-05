<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Security\AccessCatalogSync;
use Illuminate\Database\Seeder;

/**
 * Safe on every deploy: adds new permissions and missing system roles, never overwrites
 * or restores anything an administrator changed. Companies are created by erp:install.
 */
class DatabaseSeeder extends Seeder
{
    public function run(AccessCatalogSync $catalog): void
    {
        $new = $catalog->syncPermissions();
        foreach (Tenant::all() as $tenant) {
            $catalog->syncRoles($tenant->id, $new);
        }
    }
}
