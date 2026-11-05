<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Two-way sync with the SES Licensing desktop app (licence server only): a version on every record, a change feed that
// gives pulls a cursor, the registered devices, and the used nonces and idempotency keys.
return new class extends Migration
{
    private const TABLES = ['customer' => 'vendor_customers', 'plan' => 'vendor_plans', 'addon' => 'vendor_addons', 'license' => 'vendor_licenses'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('version')->default(1));
        }
        Schema::create('vendor_sync_changes', function (Blueprint $t) {
            $t->id('seq'); // the pull cursor
            $t->string('entity', 12);
            $t->uuid('record_id');
            $t->unsignedBigInteger('version');
            $t->boolean('deleted')->default(false);
            $t->timestamp('created_at')->useCurrent();
            $t->index(['entity', 'record_id']);
        });
        Schema::create('vendor_sync_devices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->index()->constrained('tenants')->restrictOnDelete(); // the audit chain its actions go in
            $t->string('name', 80);
            $t->string('status', 12)->default('pending'); // pending | active | revoked
            $t->string('enrol_hash', 64)->nullable()->unique(); // SHA-256 of the one-time code
            $t->timestamp('enrol_expires_at')->nullable();
            $t->string('public_key', 64)->nullable(); // base64 Ed25519; the private half stays in the app's vault
            $t->timestamp('registered_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->string('last_ip', 64)->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->string('created_by');
            $t->timestamps();
        });
        Schema::create('vendor_sync_nonces', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('device_id')->index()->constrained('vendor_sync_devices')->cascadeOnDelete();
            $t->string('nonce', 64);
            $t->timestamp('created_at')->useCurrent()->index();
            $t->unique(['device_id', 'nonce']);
        });
        Schema::create('vendor_sync_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('device_id')->index()->constrained('vendor_sync_devices')->cascadeOnDelete();
            $t->string('idempotency_key', 64);
            $t->jsonb('response');
            $t->timestamp('created_at')->useCurrent()->index();
            $t->unique(['device_id', 'idempotency_key']);
        });

        // Everything that exists today goes in the feed once, so a device's first pull receives it all.
        foreach (self::TABLES as $entity => $table) {
            foreach (DB::table($table)->orderBy('created_at')->orderBy('id')->pluck('id') as $id) {
                DB::table('vendor_sync_changes')->insert(['entity' => $entity, 'record_id' => $id, 'version' => 1]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_sync_requests');
        Schema::dropIfExists('vendor_sync_nonces');
        Schema::dropIfExists('vendor_sync_devices');
        Schema::dropIfExists('vendor_sync_changes');
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('version'));
        }
    }
};
