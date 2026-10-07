<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Licensing: the licence this installation runs under (one row; replicated to branch servers), installation facts,
 * and — only used when this installation is the Synclytics licence server — issued licences and check-ins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_meta', function (Blueprint $t) {
            $t->string('key', 60)->primary();
            $t->text('value')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        DB::table('system_meta')->insert(['key' => 'installation_id', 'value' => (string) Str::uuid7(), 'updated_at' => now()]);

        Schema::create('licenses', function (Blueprint $t) {
            $t->uuid('id')->primary(); // the licence id from the key
            $t->text('license_key');
            $t->jsonb('payload');
            $t->timestamp('activated_at');
            $t->string('activated_by_name');
            $t->timestamp('last_checkin_at')->nullable(); // last verified answer from the licence server
            $t->string('checkin_status', 20)->nullable(); // active, revoked, unknown_license, …
            $t->string('checkin_message')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->boolean('is_current')->default(true);
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        // Vendor side (licence server only).
        Schema::create('vendor_licenses', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('customer');
            $t->string('installation_id', 60);
            $t->jsonb('payload');
            $t->text('license_key');
            $t->string('issued_by');
            $t->timestamp('revoked_at')->nullable();
            $t->string('revoked_reason')->nullable();
            $t->uuid('superseded_by')->nullable();
            $t->timestamps();
            $t->index('installation_id');
        });
        Schema::create('vendor_builds', function (Blueprint $t) {
            $t->string('id', 20)->primary(); // build id stamped into the delivered copy
            $t->string('customer');
            $t->uuid('license_id')->nullable();
            $t->string('commit', 64)->nullable();
            $t->string('built_by');
            $t->timestamp('created_at');
        });
        Schema::create('vendor_checkins', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('license_id');
            $t->string('installation_id', 60);
            $t->string('fingerprint', 64);
            $t->string('build_id', 60)->nullable();
            $t->boolean('tampered')->default(false);
            $t->jsonb('report');
            $t->string('ip', 64)->nullable();
            $t->string('answer', 20);
            $t->timestamp('created_at');
            $t->index(['license_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['vendor_checkins', 'vendor_builds', 'vendor_licenses', 'licenses', 'system_meta'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
