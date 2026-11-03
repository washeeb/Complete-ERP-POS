<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Service desk and maintenance (interface stage 4; paid add-on "service").
 *  - Tickets: requests and complaints from customers (WhatsApp, e-mail, the web form, the counter) and from staff, with
 *    a deadline to answer and to fix by priority (service_levels), a thread of messages and notes, warranty checks.
 *  - Assets: the company's own equipment and customers' equipment (sold or serviced), with warranty and meters;
 *    maintenance plans by time or use; work orders (planned, breakdown, customer visit) using parts from stock, staff
 *    time and outside technicians (supplier bills), with cost and downtime.
 * Replaces the planned modules "Helpdesk" and "Assets & Maintenance".
 */
return new class extends Migration
{
    public function up(): void
    {
        // Answer and fix deadlines by priority (minutes, around the clock).
        Schema::create('service_levels', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('priority', 8);              // low | normal | high | urgent
            $t->unsignedInteger('respond_minutes');
            $t->unsignedInteger('resolve_minutes');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'priority']);
        });

        Schema::create('maintenance_assets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 30);
            $t->string('name');
            $t->string('owner', 10)->default('own');  // own | customer
            $t->string('category', 40)->nullable();  // generator, fridge, vehicle, till, computer, air conditioner…
            $t->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('location')->nullable();
            $t->string('make', 80)->nullable();
            $t->string('model', 80)->nullable();
            $t->string('serial_no', 80)->nullable();
            $t->foreignUuid('customer_id')->nullable()->constrained()->restrictOnDelete(); // customers' equipment
            $t->foreignUuid('product_id')->nullable()->constrained()->restrictOnDelete();  // what was sold to them
            $t->foreignUuid('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('fixed_asset_id')->nullable()->constrained()->nullOnDelete(); // the books' record of it
            $t->date('acquired_on')->nullable();
            $t->date('warranty_until')->nullable();
            $t->date('contract_until')->nullable();   // service contract (customers' equipment)
            $t->string('meter_unit', 10)->nullable(); // hours | km
            $t->decimal('meter_reading', 12, 1)->nullable();
            $t->string('status', 10)->default('working'); // working | down | retired
            $t->text('notes')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'owner', 'status']);
            $t->index('branch_id');
            $t->index('customer_id');
            $t->index('product_id');
            $t->index('supplier_id');
            $t->index('fixed_asset_id');
            $t->index('serial_no');
        });

        Schema::create('maintenance_plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('asset_id')->constrained('maintenance_assets')->cascadeOnDelete();
            $t->string('name');
            $t->unsignedSmallInteger('every_days')->nullable();
            $t->decimal('every_meter', 12, 1)->nullable(); // every 250 hours / 5,000 km
            $t->json('checklist')->nullable();
            $t->foreignUuid('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->date('last_done_on')->nullable();
            $t->decimal('last_meter', 12, 1)->nullable();
            $t->date('next_due_on')->nullable();
            $t->unsignedSmallInteger('lead_days')->default(7); // the work order opens this many days early
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index('asset_id');
            $t->index('assigned_to');
            $t->index(['tenant_id', 'is_active', 'next_due_on']);
        });

        Schema::create('tickets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 30);
            $t->string('subject');
            $t->text('description')->nullable();
            $t->string('kind', 12)->default('support');  // complaint | return | warranty | support | request | internal
            $t->string('source', 10)->default('counter'); // whatsapp | email | web | phone | counter | staff | sensor
            $t->string('priority', 8)->default('normal');
            $t->string('status', 10)->default('new');      // new | open | waiting | resolved | closed
            $t->foreignUuid('customer_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('contact_name', 120)->nullable();
            $t->string('contact_phone', 40)->nullable();
            $t->string('contact_email')->nullable();
            $t->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUuid('asset_id')->nullable()->constrained('maintenance_assets')->nullOnDelete();
            $t->string('receipt_no', 40)->nullable();   // the sale it is about (returns, warranty)
            $t->boolean('under_warranty')->nullable();
            $t->foreignUuid('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('raised_by_name')->nullable();
            $t->timestamp('respond_by')->nullable();
            $t->timestamp('resolve_by')->nullable();
            $t->timestamp('first_response_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamp('paused_at')->nullable();      // waiting for the customer: the clock stops
            $t->timestamp('warned_at')->nullable();      // deadline near: the person was told
            $t->timestamp('escalated_at')->nullable();   // deadline missed: managers were told
            $t->string('resolution', 500)->nullable();
            $t->unsignedTinyInteger('rating')->nullable(); // 1–5 from the customer
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'status']);
            $t->index('customer_id');
            $t->index('branch_id');
            $t->index('assigned_to');
            $t->index('asset_id');
            $t->index('raised_by');
            $t->index('contact_phone');
        });

        Schema::create('ticket_messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('ticket_id')->constrained()->cascadeOnDelete();
            $t->string('direction', 4);              // in (from them) | out (to them) | note (staff only)
            $t->string('channel', 10)->nullable();   // whatsapp | sms | email | web | phone | counter
            $t->text('body');
            $t->json('files')->nullable();
            $t->foreignUuid('message_id')->nullable()->constrained()->nullOnDelete(); // in the message log (sent)
            $t->string('author_name')->nullable();
            $t->timestamps();
            $t->index('ticket_id');
            $t->index('message_id');
            $t->index('tenant_id');
        });

        Schema::create('maintenance_orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 30);
            $t->foreignUuid('asset_id')->constrained('maintenance_assets')->restrictOnDelete();
            $t->foreignUuid('plan_id')->nullable()->constrained('maintenance_plans')->nullOnDelete();
            $t->foreignUuid('ticket_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('kind', 10);                  // planned | breakdown | visit (customers' equipment)
            $t->string('title');
            $t->text('details')->nullable();
            $t->string('priority', 8)->default('normal');
            $t->string('status', 10)->default('open'); // open | working | waiting | done | cancelled
            $t->foreignUuid('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->date('due_on')->nullable();
            $t->json('checklist')->nullable();       // [{item, done}]
            $t->timestamp('down_from')->nullable();  // the asset stopped working
            $t->timestamp('down_until')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('done_at')->nullable();
            $t->decimal('meter_reading', 12, 1)->nullable();
            $t->unsignedInteger('labour_minutes')->default(0);
            $t->bigInteger('hourly_cost_minor')->default(0);
            $t->bigInteger('parts_minor')->default(0);
            $t->bigInteger('outside_minor')->default(0);
            $t->boolean('chargeable')->default(false); // a customer's equipment out of warranty and contract
            $t->foreignUuid('invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->string('outcome', 500)->nullable();
            $t->string('source', 10)->default('staff'); // staff | plan | ticket | sensor
            $t->string('created_by_name')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'status']);
            $t->index('asset_id');
            $t->index('plan_id');
            $t->index('ticket_id');
            $t->index('branch_id');
            $t->index('assigned_to');
            $t->index('invoice_id');
        });

        // Parts used on a work order (stock out of a store, or back), at cost.
        Schema::create('maintenance_parts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('order_id')->constrained('maintenance_orders')->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->decimal('qty', 14, 3);              // base units; negative = back to stock
            $t->bigInteger('cost_minor');
            $t->foreignUuid('stock_movement_id')->nullable()->constrained()->nullOnDelete();
            $t->string('created_by_name')->nullable();
            $t->timestamps();
            $t->index('order_id');
            $t->index('warehouse_id');
            $t->index('product_id');
            $t->index('stock_movement_id');
            $t->index('tenant_id');
        });

        Schema::table('supplier_bill_lines', function (Blueprint $t) {
            $t->foreignUuid('maintenance_order_id')->nullable()->constrained()->nullOnDelete();
            $t->index('maintenance_order_id');
        });

        // "Helpdesk" and "Assets & Maintenance" were planned modules branches could reserve; they are this add-on now.
        DB::table('branch_modules')->whereIn('module_key', ['helpdesk', 'assets'])->where('status', 'reserved')->delete();
    }

    public function down(): void
    {
        Schema::table('supplier_bill_lines', fn (Blueprint $t) => $t->dropConstrainedForeignId('maintenance_order_id'));
        Schema::dropIfExists('maintenance_parts');
        Schema::dropIfExists('maintenance_orders');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('maintenance_plans');
        Schema::dropIfExists('maintenance_assets');
        Schema::dropIfExists('service_levels');
    }
};
