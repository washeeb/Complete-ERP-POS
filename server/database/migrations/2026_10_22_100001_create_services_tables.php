<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10c: Services & Packages — bookable services with a duration, appointments in staff calendars, prepaid packs
 * (passes counted down at the till), memberships billed by recurring invoice, commissions for the staff who did the
 * work (paid through payroll), and licence keys handed out when a software product is sold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->unsignedSmallInteger('service_minutes')->nullable(); // a bookable service and how long it takes
            $t->unsignedInteger('commission_bp')->nullable(); // overrides the staff member's own rate for this item
            $t->jsonb('pack')->nullable(); // prepaid pack: {items: [{label, product_ids, sessions}], valid_days}
            $t->boolean('licence_keys')->default(false); // each one sold hands out a licence key from the pool
        });

        Schema::table('employees', function (Blueprint $t) {
            $t->boolean('takes_appointments')->default(false); // shows in the staff calendar and the till's "done by" list
            $t->unsignedInteger('commission_bp')->default(0); // share of the price before tax of what they do or sell
        });

        // What a customer has prepaid: sessions per item, counted down by uses (append-only). From a pack sold at the
        // till or a membership period.
        Schema::create('service_passes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete(); // sold or billed at
            $t->string('source', 12); // pack | membership
            $t->uuid('sale_id')->nullable();
            $t->uuid('sale_line_id')->nullable();
            $t->uuid('membership_id')->nullable();
            $t->string('name', 160);
            $t->jsonb('items'); // [{label, product_ids, sessions (null = unlimited)}]
            $t->bigInteger('session_value_minor')->default(0); // price before tax per session, the commission base when one is used
            $t->date('valid_from');
            $t->date('expires_on')->nullable();
            $t->string('status', 12)->default('active'); // active | void
            $t->string('void_reason')->nullable();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['customer_id', 'status']);
        });

        Schema::create('service_pass_uses', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('service_pass_id')->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('item'); // index into the pass's items
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->integer('sessions'); // +1 used, −1 given back (a return)
            $t->uuid('branch_id');
            $t->uuid('sale_id')->nullable();
            $t->uuid('sale_line_id')->nullable();
            $t->string('by_name', 120);
            $t->timestamp('occurred_at', 6);
            $t->string('origin_node', 20)->nullable();
            $t->timestamp('created_at', 6)->nullable();
            $t->index(['service_pass_id']);
        });

        Schema::create('appointments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->uuid('customer_id')->nullable();
            $t->string('customer_name', 120);
            $t->string('phone', 20)->nullable();
            $t->foreignUuid('employee_id')->constrained()->restrictOnDelete(); // who does it
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete(); // the service
            $t->timestamp('starts_at', 6);
            $t->timestamp('ends_at', 6);
            $t->string('status', 12); // booked, arrived, done, no_show, cancelled
            $t->string('note')->nullable();
            $t->uuid('sale_id')->nullable(); // paid on this sale
            $t->timestamp('reminder_sent_at', 6)->nullable();
            $t->string('created_by_name', 120)->nullable();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['employee_id', 'starts_at']);
            $t->index(['branch_id', 'starts_at']);
        });

        Schema::create('membership_plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('code', 30);
            $t->string('name');
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete(); // what the invoice line is (its tax group taxes it)
            $t->bigInteger('price_minor'); // per period, as on the invoice (tax inclusive like the default price list)
            $t->unsignedTinyInteger('interval_months')->default(1);
            $t->jsonb('items')->default('[]'); // sessions included each period (empty = billing only)
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('memberships', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('membership_plan_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete(); // invoiced from
            $t->string('status', 12); // active, paused, cancelled
            $t->bigInteger('price_minor'); // agreed price per period
            $t->date('starts_on');
            $t->date('next_bill_on'); // the next period starts (and is invoiced) on this day
            $t->date('ends_on')->nullable(); // last day covered after a cancellation
            $t->string('cancel_reason')->nullable();
            $t->uuid('last_invoice_id')->nullable();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['status', 'next_bill_on']);
        });

        // Commission earned on each line a staff member did (negative for returns); paid with the month's payroll.
        Schema::create('commissions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('employee_id')->constrained()->restrictOnDelete();
            $t->uuid('sale_id');
            $t->uuid('sale_line_id');
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->bigInteger('base_minor'); // line value before tax
            $t->unsignedInteger('rate_bp');
            $t->bigInteger('amount_minor');
            $t->timestamp('occurred_at', 6);
            $t->string('origin_node', 20)->nullable();
            $t->timestamp('created_at', 6)->nullable();
            $t->index(['employee_id', 'occurred_at']);
        });

        // Software licence keys bought in and handed out one per unit sold. Kept as text (not encrypted with this server's
        // APP_KEY, which other servers do not share), shown only to people allowed to manage them and on the receipt.
        Schema::create('licence_keys', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete(); // sold only at this branch (no two servers hand out the same key)
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->text('licence_key');
            $t->char('fingerprint', 64); // sha256 of the key: no duplicates, without decrypting
            $t->string('hint', 8); // last characters, to recognise it
            $t->string('status', 12)->default('available'); // available, sold, returned, void
            $t->uuid('sale_id')->nullable();
            $t->uuid('sale_line_id')->nullable();
            $t->timestamp('sold_at', 6)->nullable();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'fingerprint']);
            $t->index(['product_id', 'branch_id', 'status']);
        });

        Schema::table('sale_lines', function (Blueprint $t) {
            $t->uuid('staff_id')->nullable(); // employee who did the service (commission)
            $t->uuid('service_pass_id')->nullable(); // paid with a session from this pass
            $t->unsignedSmallInteger('pass_item')->nullable();
            $t->uuid('appointment_id')->nullable();
        });

        Schema::table('payslips', fn (Blueprint $t) => $t->bigInteger('commission_minor')->default(0));
        Schema::table('invoices', fn (Blueprint $t) => $t->uuid('membership_id')->nullable()); // a membership period's invoice
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('membership_id'));
        Schema::table('payslips', fn (Blueprint $t) => $t->dropColumn('commission_minor'));
        Schema::table('sale_lines', fn (Blueprint $t) => $t->dropColumn(['staff_id', 'service_pass_id', 'pass_item', 'appointment_id']));
        foreach (['licence_keys', 'commissions', 'memberships', 'membership_plans', 'appointments', 'service_pass_uses', 'service_passes'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn(['takes_appointments', 'commission_bp']));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['service_minutes', 'commission_bp', 'pack', 'licence_keys']));
    }
};
