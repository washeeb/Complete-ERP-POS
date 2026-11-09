<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Archiving for every record that cannot be deleted because history points at it (owner 2026-11-09), as industries,
 * legal entities and branches got on 2026-11-08: when, by whom and why (App\Domain\Org\ArchiveRules).
 *
 * Where the on/off switch only meant "in use", rows already switched off are marked archived, so they show as such and
 * can be restored. Where it means something else (a product not for sale, a paused promotion), nothing is marked.
 */
return new class extends Migration
{
    /** Tables whose switch only meant "in use". */
    private const IN_USE_SWITCH = ['warehouses', 'tills', 'bins', 'categories', 'price_lists', 'users', 'customers', 'suppliers', 'accounts',
        'bank_accounts', 'tax_rates', 'dining_tables', 'kitchen_stations', 'routes', 'work_centres', 'iot_sensors', 'crm_stages'];

    /** Tables with a switch of their own meaning, or none. */
    private const OTHERS = ['devices', 'products', 'pricing_rules', 'promotions', 'styles', 'employees', 'departments', 'roles', 'modifier_groups',
        'membership_plans', 'message_templates', 'sales_channels', 'maintenance_assets'];

    public function up(): void
    {
        foreach ([...self::IN_USE_SWITCH, ...self::OTHERS] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestamp('archived_at')->nullable();
                $t->foreignUuid('archived_by')->nullable()->constrained('users')->nullOnDelete();
                $t->string('archive_reason', 255)->nullable();
                $t->index('archived_by');
            });
        }
        foreach (self::IN_USE_SWITCH as $table) {
            DB::table($table)->where('is_active', false)->update(['archived_at' => now(), 'archive_reason' => 'Switched off before archiving existed']);
        }
    }

    public function down(): void
    {
        foreach ([...self::IN_USE_SWITCH, ...self::OTHERS] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('archived_by');
                $t->dropColumn(['archived_at', 'archive_reason']);
            });
        }
    }
};
