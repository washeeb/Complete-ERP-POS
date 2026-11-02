<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interface stage 1b, each person's own:
 *  - dashboard: the panels on their home page, in their order and size (null = their role's default);
 *  - notification choices: which topics reach them, by which channel (null = everything the company sends);
 *  - preferences: home page, rows per list, light/dark, date style, density (null = the standard).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->json('dashboard')->nullable();
            $t->json('notify_prefs')->nullable();
            $t->json('ui_prefs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['dashboard', 'notify_prefs', 'ui_prefs']);
        });
    }
};
