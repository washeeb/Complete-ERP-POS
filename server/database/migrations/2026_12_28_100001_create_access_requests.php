<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Access changes asked for by IT Admin, waiting for the owner or SyncLytics support (owner decision 2026-11-13: "IT asks,
 * Superadmin or owner approves"; unanswered requests lapse after 72 hours). The payload never holds a password or PIN,
 * only its hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('kind', 30); // user.create | user.update | access.grant | role.save | user.pin | user.reset_mfa
            $t->foreignUuid('subject_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->jsonb('payload');
            $t->text('summary'); // what it does, in plain words
            $t->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $t->string('requested_by_name');
            $t->string('status', 12)->default('pending'); // pending | approved | rejected | withdrawn | expired | failed
            $t->foreignUuid('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('decided_by_name')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note')->nullable();
            $t->timestamp('expires_at');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index('tenant_id');
            $t->index('subject_user_id');
            $t->index('requested_by');
            $t->index('decided_by');
            $t->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_requests');
    }
};
