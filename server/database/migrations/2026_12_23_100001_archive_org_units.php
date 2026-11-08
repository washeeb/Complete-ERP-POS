<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Archiving industries, legal entities and branches made by mistake, unused or closed (owner 2026-11-08): when, by whom
 * and why. An archived one is also not active, so every list that offers only active ones already leaves it out. For a
 * branch, archived_children lists the warehouses and tills it switched off, so restoring turns back on exactly those.
 * Rows already switched off before this are marked archived, so they show as such and can be restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['industries', 'legal_entities', 'branches'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->timestamp('archived_at')->nullable();
                $t->foreignUuid('archived_by')->nullable()->constrained('users')->nullOnDelete();
                $t->string('archive_reason', 255)->nullable();
                if ($table === 'branches') {
                    $t->json('archived_children')->nullable();
                }
                $t->index('archived_by');
            });
            DB::table($table)->where('is_active', false)->update(['archived_at' => now(), 'archive_reason' => 'Switched off before archiving existed']);
        }
    }

    public function down(): void
    {
        foreach (['industries', 'legal_entities', 'branches'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropConstrainedForeignId('archived_by');
                $t->dropColumn(array_merge(['archived_at', 'archive_reason'], $table === 'branches' ? ['archived_children'] : []));
            });
        }
    }
};
