<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Separate books per industry became the default (owner, 2026-10-13) for new installations. Companies already running
 * keep one set of books, since switching mid-year would split their figures; they can switch at a year start.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::table('settings')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'key' => 'finance.separate_books',
                'value' => json_encode(false), 'version' => 1, 'origin_node' => (string) config('erp.node.id'), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'finance.separate_books')->delete();
    }
};
