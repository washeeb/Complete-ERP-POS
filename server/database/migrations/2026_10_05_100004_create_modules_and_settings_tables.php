<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_modules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->string('module_key', 50);
            // enabled | disabled | reserved. "reserved" = chosen for this branch but the module
            // is not released yet; it activates in its phase. Never shown as working before then.
            $table->string('status', 20);
            $table->string('module_version', 20)->nullable();
            $table->uuid('changed_by')->nullable(); // null = installer/system (named in the audit row)
            $table->string('reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['branch_id', 'module_key']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('key', 100);
            $table->jsonb('value');
            $table->uuid('updated_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('branch_modules');
    }
};
