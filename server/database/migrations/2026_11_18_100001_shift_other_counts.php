<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Close shift checks mobile money and card too: what the system expects per MoMo network and for the card terminal,
 * and what the person closing read from the merchant phone / terminal ({"momo:mtn": 12300, "card": 4500}).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $t) {
            $t->jsonb('expected_other')->nullable();
            $t->jsonb('counted_other')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $t) {
            $t->dropColumn(['expected_other', 'counted_other']);
        });
    }
};
