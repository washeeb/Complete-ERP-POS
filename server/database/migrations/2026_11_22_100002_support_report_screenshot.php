<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "Report a problem" keeps a picture of the screen (taken by the till, or pasted / attached in the back office). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_reports', fn (Blueprint $t) => $t->string('screenshot_path')->nullable());
    }

    public function down(): void
    {
        Schema::table('support_reports', fn (Blueprint $t) => $t->dropColumn('screenshot_path'));
    }
};
