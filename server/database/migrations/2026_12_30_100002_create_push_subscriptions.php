<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications on a manager's phone or computer even with the back office closed (owner request 2026-11-15: a till
 * asks a manager who is not at the till). Standard Web Push: each browser that said yes gets a subscription; the
 * server signs its messages with its own key pair (made once, kept here, the private half encrypted with APP_KEY).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->text('endpoint');
            $t->string('endpoint_hash', 64)->unique(); // the endpoint is long: its hash is what is looked up
            $t->string('public_key', 200);
            $t->string('auth_token', 100);
            $t->string('device', 255)->nullable(); // which browser and phone, for the person's own list
            $t->timestamp('last_sent_at')->nullable();
            $t->timestamps();
            $t->index('tenant_id');
            $t->index('user_id');
        });
        Schema::create('web_push_keys', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->unique()->constrained()->restrictOnDelete();
            $t->string('public_key', 200);
            $t->text('private_key'); // encrypted
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_push_keys');
        Schema::dropIfExists('push_subscriptions');
    }
};
