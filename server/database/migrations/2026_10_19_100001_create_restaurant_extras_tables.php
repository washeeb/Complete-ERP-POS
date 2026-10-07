<?php

use App\Domain\Finance\ChartTemplate;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9d: Restaurant extras — reservations (optional deposit, SMS reminder hook), delivery-app orders (webhook or keyed
 * in at the till; paid by the platform later), and the kitchen waste log (through the stock ledger).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('dining_table_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('customer_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('guest_name', 120);
            $t->string('phone', 20)->nullable();
            $t->unsignedSmallInteger('party_size');
            $t->timestamp('starts_at', 6);
            $t->unsignedSmallInteger('duration_minutes')->default(90);
            $t->string('note')->nullable();
            $t->string('status', 12)->default('booked'); // booked, seated, no_show, cancelled
            $t->uuid('dining_order_id')->nullable(); // the bill opened when the guests arrived
            $t->bigInteger('deposit_minor')->default(0); // held (customer deposits) until used on the bill, refunded or forfeited
            $t->bigInteger('deposit_used_minor')->default(0);
            $t->string('deposit_status', 10)->default('none'); // none, held, used, refunded, forfeited
            $t->timestamp('reminder_sent_at', 6)->nullable();
            $t->string('created_by_name');
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['branch_id', 'starts_at']);
        });

        // Deposit money in and out (at the till, so it counts in the shift's cash) — posted to the books like sales.
        Schema::create('deposit_movements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('reservation_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->uuid('shift_id')->nullable();
            $t->string('kind', 10); // taken, refunded, forfeited
            $t->string('method', 10)->nullable(); // cash, momo, card (taken / refunded)
            $t->string('provider', 20)->nullable();
            $t->string('reference', 80)->nullable();
            $t->bigInteger('amount_minor');
            $t->string('by_name');
            $t->timestamp('occurred_at', 6);
            $t->string('origin_node', 20)->nullable();
            $t->timestamps();
            $t->index(['shift_id']);
        });

        Schema::table('dining_orders', function (Blueprint $t) {
            $t->uuid('channel_id')->nullable(); // delivery app the order came from
            $t->string('platform', 20)->nullable(); // bolt_food, glovo, chowdeck
            $t->string('external_ref', 80)->nullable(); // the platform's order number
            $t->uuid('reservation_id')->nullable();
            $t->unique(['tenant_id', 'platform', 'external_ref']);
        });

        // New system accounts (delivery apps owe us; deposits and forfeits) for existing companies.
        foreach (Tenant::all() as $tenant) {
            ChartTemplate::ensure($tenant);
        }
    }

    public function down(): void
    {
        Schema::table('dining_orders', function (Blueprint $t) {
            $t->dropUnique(['tenant_id', 'platform', 'external_ref']);
            $t->dropColumn(['channel_id', 'platform', 'external_ref', 'reservation_id']);
        });
        Schema::dropIfExists('deposit_movements');
        Schema::dropIfExists('reservations');
    }
};
