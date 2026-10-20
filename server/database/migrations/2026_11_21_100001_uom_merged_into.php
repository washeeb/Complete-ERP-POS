<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A duplicate unit (PCS next to PC) merged into the right one: its products move over; it stays only because the stock
 * history refers to it, and is no longer offered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uoms', function (Blueprint $t) {
            $t->foreignUuid('merged_into_id')->nullable()->constrained('uoms')->nullOnDelete();
            $t->index('merged_into_id');
        });
    }

    public function down(): void
    {
        Schema::table('uoms', function (Blueprint $t) {
            $t->dropConstrainedForeignId('merged_into_id');
        });
    }
};
