<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM (interface stage 2; paid add-on "crm"): leads (people or businesses who may buy), deals on a pipeline of stages,
 * follow-ups (calls, meetings, tasks, notes) on a lead, deal or customer, and campaigns (one message to many customers
 * by SMS, WhatsApp or e-mail, only to those who agreed to it). A deal leads to a quotation and order (Quotes & orders);
 * confirming the order wins the deal. Kept at head office (or a standalone server), as the customers' accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_stages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('name', 60);
            $t->unsignedTinyInteger('probability')->default(10); // % chance of winning, for the weighted pipeline
            $t->string('kind', 6)->default('open');              // open | won | lost
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['tenant_id', 'sort']);
        });

        Schema::create('crm_leads', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 30);
            $t->string('name');                     // the person
            $t->string('company_name')->nullable(); // their business, if any
            $t->string('phone', 40)->nullable();
            $t->string('email')->nullable();
            $t->string('source', 20)->default('walk_in'); // walk_in | phone | whatsapp | website | referral | event | social | other
            $t->string('interest', 255)->nullable();       // what they asked about
            $t->bigInteger('value_minor')->nullable();    // what it may be worth
            $t->string('status', 12)->default('new');     // new | contacted | qualified | converted | lost
            $t->string('lost_reason', 255)->nullable();
            $t->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('customer_id')->nullable()->constrained()->restrictOnDelete(); // once converted
            $t->json('consents')->nullable(); // as customers: {sms: {granted, at, source}, …}
            $t->text('notes')->nullable();
            $t->string('created_by_name')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'status']);
            $t->index('owner_id');
            $t->index('branch_id');
            $t->index('customer_id');
        });

        Schema::create('crm_deals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 30);
            $t->string('title');
            $t->foreignUuid('customer_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('lead_id')->nullable()->constrained('crm_leads')->restrictOnDelete();
            $t->foreignUuid('stage_id')->constrained('crm_stages')->restrictOnDelete();
            $t->bigInteger('value_minor')->default(0);
            $t->string('currency_code', 3);
            $t->date('expected_close_on')->nullable();
            $t->string('status', 6)->default('open'); // open | won | lost
            $t->string('lost_reason', 255)->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('customer_order_id')->nullable()->constrained()->nullOnDelete(); // its quotation / order
            $t->text('notes')->nullable();
            $t->string('created_by_name')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'status']);
            $t->index('customer_id');
            $t->index('lead_id');
            $t->index('stage_id');
            $t->index('owner_id');
            $t->index('branch_id');
            $t->index('customer_order_id');
        });

        Schema::create('crm_activities', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('type', 10);                 // call | meeting | task | note | message
            $t->string('subject');
            $t->text('details')->nullable();
            $t->foreignUuid('lead_id')->nullable()->constrained('crm_leads')->cascadeOnDelete();
            $t->foreignUuid('deal_id')->nullable()->constrained('crm_deals')->cascadeOnDelete();
            $t->foreignUuid('customer_id')->nullable()->constrained()->restrictOnDelete();
            $t->timestamp('due_at')->nullable();    // a follow-up to do; null = something that happened (a note, a call made)
            $t->timestamp('done_at')->nullable();
            $t->string('outcome', 255)->nullable();
            $t->timestamp('reminded_at')->nullable();
            $t->foreignUuid('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->string('created_by_name')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['tenant_id', 'done_at', 'due_at']);
            $t->index('lead_id');
            $t->index('deal_id');
            $t->index('customer_id');
            $t->index('assigned_to');
        });

        Schema::create('crm_campaigns', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 30);
            $t->string('name');
            $t->string('channel', 10);               // sms | whatsapp | email
            $t->string('subject')->nullable();       // e-mail
            $t->text('body');
            $t->string('whatsapp_template', 80)->nullable(); // the template approved by WhatsApp (needed outside a 24-hour chat)
            $t->json('audience');                    // {tags, branch_id, bought_within_days, not_bought_days, min_spent_minor, include_leads}
            $t->string('status', 10)->default('draft'); // draft | sending | sent | cancelled
            $t->unsignedInteger('recipients')->default(0);
            $t->unsignedInteger('sent')->default(0);
            $t->unsignedInteger('failed')->default(0);
            $t->unsignedInteger('skipped')->default(0); // no consent, no usable number or address
            $t->timestamp('send_at')->nullable();    // later, or null = at once
            $t->timestamp('sent_at')->nullable();
            $t->string('created_by_name')->nullable();
            $t->string('sent_by_name')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'status']);
        });

        Schema::create('crm_campaign_recipients', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('campaign_id')->constrained('crm_campaigns')->cascadeOnDelete();
            $t->foreignUuid('customer_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('lead_id')->nullable()->constrained('crm_leads')->restrictOnDelete();
            $t->foreignUuid('message_id')->nullable()->constrained()->nullOnDelete();
            $t->string('to', 160)->nullable();
            $t->string('status', 10);                // queued | skipped
            $t->string('skip_reason', 60)->nullable();
            $t->timestamps();
            $t->index('campaign_id');
            $t->index('customer_id');
            $t->index('lead_id');
            $t->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_campaign_recipients');
        Schema::dropIfExists('crm_campaigns');
        Schema::dropIfExists('crm_activities');
        Schema::dropIfExists('crm_deals');
        Schema::dropIfExists('crm_leads');
        Schema::dropIfExists('crm_stages');
    }
};
