<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Release 4 (owner request 2026-10-22):
 *  - dosage: a product's usual directions, and the directions given on each sale line (printed on the receipt and a label);
 *  - automatic shifts: the shift a till session belongs to (Morning, Afternoon, Straight day…), and who allowed a till to
 *    open outside trading hours or again after it was closed, and why;
 *  - the default shifts become Morning 07:00–15:00 and Afternoon 14:00–23:00 where a company still has the first defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->string('dosage', 255)->nullable());
        Schema::table('sale_lines', fn (Blueprint $t) => $t->string('dosage', 255)->nullable());
        Schema::table('shifts', function (Blueprint $t) {
            $t->string('shift_name', 40)->nullable();
            $t->string('granted_by_name', 120)->nullable();
            $t->string('grant_reason', 255)->nullable();
        });

        // Only rows still exactly at the old defaults: a company that changed its times keeps them.
        foreach ([['Morning', '07:00:00', '14:00:00', '15:00'], ['Afternoon', '14:00:00', '21:00:00', '23:00']] as [$name, $from, $to, $new]) {
            DB::table('shift_templates')->where('name', $name)->where('starts_at', $from)->where('ends_at', $to)
                ->update(['ends_at' => $new, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('dosage'));
        Schema::table('sale_lines', fn (Blueprint $t) => $t->dropColumn('dosage'));
        Schema::table('shifts', fn (Blueprint $t) => $t->dropColumn(['shift_name', 'granted_by_name', 'grant_reason']));
    }
};
