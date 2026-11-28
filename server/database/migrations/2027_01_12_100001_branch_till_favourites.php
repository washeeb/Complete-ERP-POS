<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Favourite tiles on a branch's tills (till new look, part 1b): the products shown first, in this order, as a list of
 * product ids. Kept apart from `settings`, which holds only the branch's own values of company settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->jsonb('till_favourites')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('till_favourites');
        });
    }
};
