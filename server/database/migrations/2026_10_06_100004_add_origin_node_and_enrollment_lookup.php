<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §3.2: every row carries the node it was created on. Phase 1 tables predate that column;
 * their rows were all created on this node, so this node's id is the truthful backfill.
 */
return new class extends Migration
{
    private const TABLES = ['tenants', 'legal_entities', 'branches', 'warehouses', 'tills', 'devices', 'roles',
        'role_assignments', 'branch_modules', 'settings', 'users'];

    public function up(): void
    {
        $node = (string) config('erp.node.id');
        foreach (self::TABLES as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->string('origin_node', 40)->nullable());
            DB::table($t)->update(['origin_node' => $node]);
            DB::statement("ALTER TABLE {$t} ALTER COLUMN origin_node SET NOT NULL");
        }

        // Enrol with the code alone: a short clear prefix finds the device, the full code is checked by hash.
        Schema::table('devices', fn (Blueprint $table) => $table->string('enrollment_lookup', 4)->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('devices', fn (Blueprint $table) => $table->dropColumn('enrollment_lookup'));
        foreach (self::TABLES as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn('origin_node'));
        }
    }
};
