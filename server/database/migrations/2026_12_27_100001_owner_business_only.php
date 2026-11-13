<?php

use App\Domain\Access\OwnerSeparation;
use App\Security\AccessCatalogSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Owner runs the business; IT Admin and SyncLytics support do the technical side (owner decision 2026-11-13).
 * Once: the new "business settings" permission and API keys as a technical permission reach the roles, IT Admin gets API
 * keys, and in every company where the support account or an IT Admin exists the Owner role gives up its technical
 * permissions (it keeps seeing users and their access). Elsewhere it keeps them until one is set up, so nobody is
 * left unable to add a user; the owner sees a banner until then.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sync = app(AccessCatalogSync::class);
        $created = $sync->syncPermissions(); // settings.business is new; api.manage becomes technical
        foreach (DB::table('tenants')->pluck('id') as $tenant) {
            $sync->syncRoles($tenant, $created);
            $itAdmin = DB::table('roles')->where('tenant_id', $tenant)->where('key', 'it_admin')->value('id');
            if ($itAdmin && DB::table('permissions')->where('key', 'api.manage')->exists()
                && ! DB::table('permission_role')->where('role_id', $itAdmin)->where('permission_key', 'api.manage')->exists()) {
                DB::table('permission_role')->insert(['role_id' => $itAdmin, 'permission_key' => 'api.manage']);
            }
            app(OwnerSeparation::class)->apply($tenant, 'Owner runs the business; technical access stays with IT Admin and SyncLytics support');
        }
    }

    public function down(): void
    {
        // Not undone: putting technical access back on the Owner role is a decision for IT Admin or SyncLytics support.
    }
};
