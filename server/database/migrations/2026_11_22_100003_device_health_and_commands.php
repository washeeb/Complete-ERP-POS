<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device health (the till's latest snapshot: version, unsent work, connection, storage, printer) and remote fixes
 * queued for a device (reload, send waiting sales, refresh the catalogue, clear the cache, collect logs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $t) {
            $t->jsonb('health')->nullable();
            $t->timestamp('health_at')->nullable();
        });

        Schema::create('device_commands', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('device_id')->constrained()->restrictOnDelete();
            $t->string('command', 30); // reload | sync_now | refresh_catalog | clear_cache | collect_logs
            $t->string('status', 10)->default('pending'); // pending → sent → done | failed; or cancelled
            $t->uuid('requested_by')->nullable();
            $t->string('requested_by_name');
            $t->string('reason')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('done_at')->nullable();
            $t->text('result')->nullable();
            $t->timestamps();
            $t->index('tenant_id');
            $t->index(['device_id', 'status']);
            $t->index('requested_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_commands');
        Schema::table('devices', fn (Blueprint $t) => $t->dropColumn(['health', 'health_at']));
    }
};
