<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refill reminders (owner request 2026-10-23): a medicine taken every day says how much a patient uses a day; a sale of
 * it to a named customer becomes a reminder sent by WhatsApp or SMS a few days before it runs out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->decimal('daily_use', 12, 3)->nullable()); // base units a day

        Schema::create('refill_reminders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('sale_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('sale_line_id')->unique()->constrained()->restrictOnDelete(); // one reminder per line sold
            $t->string('product_name', 160);
            $t->decimal('qty_base', 18, 3);
            $t->date('runs_out_on');
            $t->date('remind_on');
            $t->string('status', 12)->default('waiting'); // waiting | sent | bought | cancelled | no_contact | past
            $t->timestamp('sent_at')->nullable();
            $t->string('channel', 12)->nullable();
            $t->string('closed_by_name', 120)->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'status', 'remind_on']);
            $t->index('branch_id');
            $t->index('customer_id');
            $t->index('product_id');
            $t->index('sale_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refill_reminders');
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('daily_use'));
    }
};
