<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A till asks a manager who is not at the till (owner request 2026-11-15: "the manager may not be near the till PC, and
 * typing the manager's PIN at the till shows it"). The manager approves on their phone or in the back office; the till
 * then goes on with that one approval, once. A manager who is at the till can still approve there (card or username and
 * PIN). Nothing here holds a PIN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('till_approvals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('till_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('device_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('cashier_id')->constrained('users')->restrictOnDelete();
            $t->string('cashier_name');
            $t->string('kind', 20); // return | discount | void | no_sale | drawer | restaurant_void | credit
            $t->string('permission', 60);
            $t->string('limit_key', 40)->nullable();
            $t->bigInteger('amount_minor')->default(0); // money involved (shown); for a discount the limit amount is in limit_amount
            $t->bigInteger('limit_amount')->default(0); // what the approver's limit is checked against (minor units or basis points)
            $t->text('summary'); // what is asked, in plain words
            $t->jsonb('details')->nullable(); // lines shown to the manager
            $t->string('reason')->nullable();
            $t->string('status', 12)->default('pending'); // pending | approved | declined | cancelled | expired | used
            $t->foreignUuid('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('decided_by_name')->nullable();
            $t->string('decided_via', 10)->nullable(); // remote | till
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note')->nullable();
            $t->timestamp('used_at')->nullable();
            $t->timestamp('expires_at');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index('tenant_id');
            $t->index('branch_id');
            $t->index('till_id');
            $t->index('device_id');
            $t->index('cashier_id');
            $t->index('decided_by');
            $t->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('till_approvals');
    }
};
