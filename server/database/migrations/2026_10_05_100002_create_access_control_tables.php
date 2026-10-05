<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rows mirror the permission declarations in modules/*/module.json (synced by the seeder).
        Schema::create('permissions', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->string('module', 50);
            $table->string('label');
            $table->unsignedSmallInteger('min_level')->default(1);
            // Technical permissions (users, devices, modules, settings) are the only ones an IT role may hold.
            $table->boolean('is_technical')->default(false);
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('key', 60);
            $table->string('name');
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('security_level');
            $table->boolean('is_system')->default(false);
            $table->boolean('is_technical')->default(false);
            $table->jsonb('default_limits')->default('{}');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            $table->string('permission_key', 100);
            $table->foreign('permission_key')->references('key')->on('permissions')->restrictOnDelete();
            $table->primary(['role_id', 'permission_key']);
        });

        Schema::create('role_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('role_id')->constrained()->restrictOnDelete();
            // tenant | legal_entity | branch | warehouse | till. Missing scope is never global scope.
            $table->string('scope_type', 20);
            $table->uuid('scope_id');
            $table->jsonb('limits')->default('{}'); // e.g. {"discount_pct": 10, "refund_minor": 50000}
            $table->timestamp('starts_at');
            $table->timestamp('expires_at')->nullable(); // set = temporary elevated access
            $table->string('reason')->nullable();
            $table->uuid('granted_by');
            $table->string('granted_by_name');
            $table->timestamp('revoked_at')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->string('revoke_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
