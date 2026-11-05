<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Invoices and payments join the sync with the SES Licensing desktop app. Those already here go in the feed once, after
// their customers, so a device's next pull receives them.
return new class extends Migration
{
    private const TABLES = ['invoice' => 'vendor_invoices', 'payment' => 'vendor_payments'];

    public function up(): void
    {
        foreach (self::TABLES as $entity => $table) {
            Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('version')->default(1));
            foreach (DB::table($table)->orderBy('created_at')->orderBy('id')->pluck('id') as $id) {
                DB::table('vendor_sync_changes')->insert(['entity' => $entity, 'record_id' => $id, 'version' => 1]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $entity => $table) {
            DB::table('vendor_sync_changes')->where('entity', $entity)->delete();
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('version'));
        }
    }
};
