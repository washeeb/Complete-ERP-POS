<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company owner lets SyncLytics support reach its devices for a set time (or until a problem report is closed):
 * view only or full control, all branches or one. Ends by itself; can be withdrawn at any moment. Free for support.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_access_grants', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete(); // null = every branch
            $t->string('level', 10); // view | control
            $t->timestamp('starts_at');
            $t->timestamp('ends_at')->nullable(); // null = until the problem report is closed
            $t->foreignUuid('support_report_id')->nullable()->constrained()->nullOnDelete();
            $t->string('reason');
            $t->foreignUuid('granted_by')->constrained('users')->restrictOnDelete();
            $t->string('granted_by_name');
            $t->timestamp('revoked_at')->nullable();
            $t->string('revoked_by_name')->nullable();
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index('tenant_id');
            $t->index('branch_id');
            $t->index('support_report_id');
            $t->index('granted_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_access_grants');
    }
};
