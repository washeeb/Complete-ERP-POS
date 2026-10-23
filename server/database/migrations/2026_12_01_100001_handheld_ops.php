<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline handhelds (owner request 2026-10-23): each warehouse action a handheld makes (receive, put away, receive a
 * transfer, count) carries the handheld's own id. The first time it arrives it is done and its answer kept here; when
 * the same action arrives again (sent offline, then resent; or its answer was lost) the kept answer is returned and
 * nothing is done twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('handheld_ops', function (Blueprint $t) {
            $t->uuid('id')->primary(); // made by the handheld
            $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('device_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('op', 30);
            $t->jsonb('result');
            $t->timestamp('done_at');
            $t->index('tenant_id');
            $t->index('device_id');
            $t->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('handheld_ops');
    }
};
