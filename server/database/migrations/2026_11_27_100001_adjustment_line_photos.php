<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A photo of the damaged, expired or found goods on a stock adjustment line (Stock Controller release 3). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_adjustment_lines', fn (Blueprint $t) => $t->string('photo_path')->nullable());
    }

    public function down(): void
    {
        Schema::table('stock_adjustment_lines', fn (Blueprint $t) => $t->dropColumn('photo_path'));
    }
};
