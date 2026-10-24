<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders by WhatsApp (owner request 2026-10-24): a customer's WhatsApp message to the business number — a list of
 * medicines, a photo of a prescription — arrives here for staff to turn into an online order (stock held for it) or
 * decline, and the customer is answered on WhatsApp. Messages from one number close together join one entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('number', 40); // WA-HQ-000123, what staff and the customer quote
            $t->string('from_phone', 20); // international digits, e.g. 233241112222
            $t->string('from_name', 120)->nullable(); // the name on their WhatsApp profile
            $t->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete(); // matched by phone number
            $t->text('body')->nullable(); // the text of every message in the entry
            $t->jsonb('media')->default('[]'); // photos / documents: {id, mime, caption, path, error}
            $t->jsonb('message_ids')->default('[]'); // WhatsApp's ids, so a message Meta sends twice is kept once
            $t->string('status', 12)->default('new'); // new | ordered | declined
            $t->foreignUuid('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $t->string('handled_by_name', 120)->nullable();
            $t->timestamp('handled_at')->nullable();
            $t->string('decline_reason')->nullable();
            $t->timestamp('received_at');
            $t->timestamp('last_message_at');
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'status', 'last_message_at']);
            $t->index(['tenant_id', 'from_phone', 'status']);
            $t->index('customer_id');
            $t->index('sales_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_orders');
    }
};
