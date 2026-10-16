<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which computer made each change (browser and system; for the desktop till also its name, MAC and shop-network
// address), and where each till was last used from. Not part of the audit hash, like the IP address.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', fn (Blueprint $t) => $t->jsonb('client')->nullable());
        Schema::table('devices', function (Blueprint $t) {
            $t->string('last_ip', 45)->nullable();
            $t->jsonb('last_client')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', fn (Blueprint $t) => $t->dropColumn('client'));
        Schema::table('devices', fn (Blueprint $t) => $t->dropColumn(['last_ip', 'last_client']));
    }
};
