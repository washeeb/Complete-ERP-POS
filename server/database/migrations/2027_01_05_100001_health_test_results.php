<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Health checks that give a result rather than a number (owner request 2026-11-21): an HIV or typhoid rapid test is
 * non-reactive / reactive, negative / positive, or invalid. HB (haemoglobin) uses the existing value; a wound dressing
 * keeps what was done in the note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_readings', fn (Blueprint $t) => $t->string('result', 20)->nullable());
    }

    public function down(): void
    {
        Schema::table('health_readings', fn (Blueprint $t) => $t->dropColumn('result'));
    }
};
