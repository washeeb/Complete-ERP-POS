<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each consumer of the outbox (live updates, each sales channel) keeps its own position, so
        // one slow store never delays the tills. Rows are claimed with SKIP LOCKED: one relay per consumer.
        Schema::create('outbox_cursors', function (Blueprint $t) {
            $t->string('consumer', 80)->primary();
            $t->unsignedBigInteger('last_seq')->default(0);
            $t->timestamp('updated_at')->nullable();
        });

        Schema::create('sales_channels', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('type', 20); // storefront | woocommerce | shopify | rest
            $t->string('name');
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();       // fulfils the orders
            $t->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();    // stock it sells from
            $t->foreignUuid('price_list_id')->constrained()->restrictOnDelete();   // prices it publishes
            $t->decimal('safety_stock', 18, 3)->default(0); // held back per product, so offline tills cannot oversell it
            $t->string('base_url')->nullable();
            $t->text('credentials')->nullable();   // encrypted JSON (API keys / tokens) — never shown again
            $t->text('webhook_secret')->nullable(); // encrypted
            $t->string('status', 12)->default('draft'); // draft | active | paused
            $t->jsonb('settings')->default('{}');
            $t->timestamp('last_reconciled_at')->nullable();
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        Schema::create('channel_products', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('channel_id')->constrained('sales_channels')->cascadeOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_uom_id')->constrained()->restrictOnDelete();
            $t->string('external_id', 100)->nullable();
            $t->string('external_variant_id', 100)->nullable();
            $t->char('last_pushed_hash', 64)->nullable(); // skip pushes that would change nothing
            $t->timestamp('last_pushed_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
            $t->unique(['channel_id', 'product_uom_id']);
            $t->index(['channel_id', 'external_id']);
        });

        // Work for a store, coalesced per (channel, kind, subject): ten price edits = one push.
        Schema::create('channel_jobs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('channel_id')->constrained('sales_channels')->cascadeOnDelete();
            $t->string('kind', 30); // product | stock | order_status
            $t->uuid('subject_id');
            $t->string('status', 10)->default('pending'); // pending | done | dead
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at');
            $t->text('last_error')->nullable();
            $t->timestamps();
        });
        DB::statement("CREATE UNIQUE INDEX channel_jobs_one_pending ON channel_jobs (channel_id, kind, subject_id) WHERE status = 'pending'");
        DB::statement("CREATE INDEX channel_jobs_due ON channel_jobs (next_attempt_at) WHERE status = 'pending'");

        // Raw webhooks, stored before anything is done with them (fast 2xx; processed asynchronously; deduplicated).
        Schema::create('webhook_inbox', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('channel_id')->constrained('sales_channels')->cascadeOnDelete();
            $t->string('delivery_id', 120);
            $t->string('topic', 80);
            $t->text('raw_body');
            $t->jsonb('headers')->default('{}');
            $t->string('status', 12)->default('received'); // received | processed | failed | ignored
            $t->unsignedInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->timestamp('received_at');
            $t->timestamp('processed_at')->nullable();
            $t->unique(['channel_id', 'delivery_id']);
        });

        Schema::create('sales_orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('channel_id')->constrained('sales_channels')->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $t->string('external_id', 100);
            $t->string('number', 40);
            // pending → confirmed (reserved) → ready (packed) → fulfilled; or cancelled
            $t->string('status', 12);
            $t->string('payment_status', 16); // unpaid | pay_at_pickup | paid | refunded
            $t->string('fulfilment', 10)->default('pickup'); // pickup | delivery
            $t->boolean('short_stock')->default(false); // accepted although not all of it could be reserved
            $t->string('customer_name');
            $t->string('customer_phone', 30)->nullable();
            $t->string('customer_email')->nullable();
            $t->jsonb('delivery_address')->nullable();
            $t->char('currency_code', 3);
            $t->boolean('prices_include_tax')->default(true);
            $t->bigInteger('net_minor');
            $t->bigInteger('tax_minor');
            $t->bigInteger('total_minor');
            $t->jsonb('tax_breakdown')->default('[]');
            $t->string('payment_method', 20)->nullable();
            $t->string('payment_reference', 80)->nullable();
            $t->text('note')->nullable();
            $t->timestamp('placed_at');
            $t->timestamp('fulfilled_at')->nullable();
            $t->string('fulfilled_by_name')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['channel_id', 'external_id']);
            $t->index(['tenant_id', 'status']);
        });

        Schema::create('sales_order_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('sales_order_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_uom_id')->constrained()->restrictOnDelete();
            $t->string('sku', 40);
            $t->string('name', 160);
            $t->string('uom_code', 10);
            $t->decimal('factor', 18, 6);
            $t->decimal('qty', 18, 3);
            $t->bigInteger('unit_price_minor');
            $t->bigInteger('line_total_minor');
            $t->bigInteger('net_minor');
            $t->bigInteger('tax_minor');
            $t->jsonb('tax_breakdown')->default('[]');
        });

        // Stock promised to an order but not yet shipped. Available = on hand − active reservations − safety stock.
        Schema::create('stock_reservations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->decimal('qty_base', 18, 3);
            $t->string('source_type', 30);
            $t->uuid('source_id');
            $t->string('status', 10)->default('active'); // active | consumed | released
            $t->timestamp('expires_at')->nullable();
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['warehouse_id', 'product_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['stock_reservations', 'sales_order_lines', 'sales_orders', 'webhook_inbox', 'channel_jobs', 'channel_products', 'sales_channels', 'outbox_cursors'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
