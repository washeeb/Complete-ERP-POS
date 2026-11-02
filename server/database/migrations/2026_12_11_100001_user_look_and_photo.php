<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The new look (stage 1a of the interface update): each person chooses how the back office looks — the classic layout,
 * the new look with tabs across the top, or the new look with the side menu (null = the company's choice under
 * System → Settings → Appearance) — and may add a profile photo (a cleaned, square copy on the server's private disk).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('ui_look', 10)->nullable();
            $t->string('avatar_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['ui_look', 'avatar_path']);
        });
    }
};
