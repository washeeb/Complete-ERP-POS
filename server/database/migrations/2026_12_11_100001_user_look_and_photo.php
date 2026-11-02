<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The new look (stage 1a of the interface update): each person chooses how the back office looks — the classic layout,
 * the new look with tabs across the top, or the new look with the side menu (null = the company's choice under
 * System → Settings → Appearance) — may add a profile photo (a cleaned, square copy on the server's private disk), and
 * keeps a short list of favourite pages (the star on the menu band), so they follow the person to any computer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('ui_look', 10)->nullable();
            $t->string('avatar_path')->nullable();
            $t->json('ui_favourites')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['ui_look', 'avatar_path', 'ui_favourites']);
        });
    }
};
