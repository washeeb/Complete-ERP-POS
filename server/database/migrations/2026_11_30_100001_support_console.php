<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support console (licence server, owner request 2026-10-23): each installation's latest hourly health report —
 * the server's checks, tills with problems, open problem reports and whether support access is open. No business data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_licenses', function (Blueprint $t) {
            $t->jsonb('health')->nullable();
            $t->timestamp('health_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vendor_licenses', fn (Blueprint $t) => $t->dropColumn(['health', 'health_at']));
    }
};
