<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * End-of-day close (owner request 2026-10-23): one record per branch and day — who closed it, when, the cash taken to
 * the bank (or kept in the safe) with the slip, the checks as they stood (tills closed, over / short, sales sent,
 * backup, approvals waiting) and the reason when something was not right.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('day_closes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->date('business_date');
            $t->bigInteger('cash_counted_minor'); // cash counted at the till closes that day
            $t->bigInteger('over_short_minor');
            $t->bigInteger('banked_minor')->nullable(); // taken to the bank
            $t->string('bank_ref', 60)->nullable(); // deposit slip number
            $t->bigInteger('safe_minor')->nullable(); // kept in the safe
            $t->jsonb('checks'); // as they stood when closed
            $t->unsignedSmallInteger('problems')->default(0);
            $t->string('note', 500)->nullable(); // required when a check was not right
            $t->foreignUuid('closed_by')->constrained('users')->restrictOnDelete();
            $t->string('closed_by_name', 120);
            $t->timestamp('closed_at');
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['branch_id', 'business_date']);
            $t->index('tenant_id');
            $t->index('closed_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_closes');
    }
};
