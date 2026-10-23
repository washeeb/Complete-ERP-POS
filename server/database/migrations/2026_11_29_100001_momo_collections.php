<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile money that confirms itself (owner request 2026-10-23): the till asks the customer's phone for the payment
 * through the company's payment provider (Paystack or Hubtel), and the payment counts only once the provider says it
 * was paid. The provider's keys stay on this server, encrypted, and are never synced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('momo_gateways', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('provider', 20); // paystack | hubtel
            $t->text('credentials'); // encrypted
            $t->string('webhook_token', 64); // in the callback address, so a callback is matched to this company
            $t->boolean('is_active')->default(false);
            $t->timestamps();
        });

        Schema::create('momo_charges', function (Blueprint $t) {
            $t->uuid('id')->primary(); // made by the till, so a retried request is the same charge
            $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('device_id')->constrained()->restrictOnDelete();
            $t->string('gateway', 20);
            $t->string('network', 12); // mtn | telecel | at
            $t->string('phone', 20);
            $t->bigInteger('amount_minor');
            $t->char('currency_code', 3);
            $t->string('reference', 40)->unique(); // ours, sent to the provider
            $t->string('provider_ref', 80)->nullable(); // the provider's transaction id
            $t->string('status', 12)->default('pending'); // pending | otp | success | failed | expired
            $t->string('message', 255)->nullable();
            $t->foreignUuid('sale_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('requested_by_name', 120);
            $t->timestamp('checked_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'status', 'created_at']);
            $t->index('branch_id');
            $t->index('device_id');
            $t->index('sale_id');
        });

        Schema::table('sale_payments', function (Blueprint $t) {
            $t->foreignUuid('momo_charge_id')->nullable()->constrained('momo_charges')->restrictOnDelete();
            $t->index('momo_charge_id');
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', fn (Blueprint $t) => $t->dropConstrainedForeignId('momo_charge_id'));
        Schema::dropIfExists('momo_charges');
        Schema::dropIfExists('momo_gateways');
    }
};
