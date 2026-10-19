<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The same file imported twice (e.g. opening stock, stock adjustment lines) is caught: each import keeps its file's fingerprint.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_imports', function (Blueprint $t) {
            $t->string('file_hash', 64)->nullable();
            $t->index(['tenant_id', 'type', 'file_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('data_imports', fn (Blueprint $t) => $t->dropColumn('file_hash'));
    }
};
