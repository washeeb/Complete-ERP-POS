<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Off-site backups became a paid add-on for new clients (owner 2026-11-07). Copies to the cloud or another server that
 * a client already set up before this keep working: they are marked here and need no add-on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_destinations', fn (Blueprint $t) => $t->boolean('grandfathered')->default(false));
        DB::table('backup_destinations')->whereIn('type', ['s3', 'gdrive', 'sftp'])->update(['grandfathered' => true]);
    }

    public function down(): void
    {
        Schema::table('backup_destinations', fn (Blueprint $t) => $t->dropColumn('grandfathered'));
    }
};
