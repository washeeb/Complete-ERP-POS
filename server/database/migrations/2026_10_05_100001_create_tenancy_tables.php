<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug', 60)->unique();
            $table->char('country_code', 2)->default('GH');
            $table->char('currency_code', 3)->default('GHS');
            $table->string('locale', 10)->default('en');
            $table->string('timezone', 64)->default('Africa/Accra');
            $table->string('deployment_mode', 20)->default('cloud'); // cloud | central | standalone
            $table->timestamp('setup_completed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
        });

        Schema::create('legal_entities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('registration_no')->nullable();
            $table->string('tin', 30)->nullable(); // Ghana TIN / GRA
            $table->string('vat_no', 30)->nullable();
            $table->char('currency_code', 3)->default('GHS');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('legal_entity_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code', 10); // used in document numbers, e.g. ACC-B01-T02-000123
            $table->string('name');
            $table->boolean('is_hq')->default(false);
            $table->string('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('timezone', 64)->nullable(); // null = tenant timezone
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->string('code', 10);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('tills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->constrained()->restrictOnDelete(); // stock source for sales
            $table->string('code', 10);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['branch_id', 'code']);
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('till_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('kind', 20); // pos | handheld | kiosk | kds
            // pending -> active -> revoked. Enrolment code is shown once to the admin, stored hashed.
            $table->string('status', 20)->default('pending');
            $table->string('enrollment_code_hash')->nullable();
            $table->timestamp('enrollment_expires_at')->nullable();
            $table->text('public_key')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->uuid('created_by');
            $table->uuid('revoked_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
        Schema::dropIfExists('tills');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('legal_entities');
        Schema::table('users', fn (Blueprint $t) => $t->dropForeign(['tenant_id']));
        Schema::dropIfExists('tenants');
    }
};
