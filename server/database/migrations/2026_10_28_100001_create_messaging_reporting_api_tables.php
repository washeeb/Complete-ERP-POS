<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 (owner decisions 2026-10-28): notifications — in-app, e-mail (any SMTP server), SMS through Hubtel, Arkesel,
 * mNotify or a generic webhook (the client chooses), WhatsApp Business (built, off until keys); reporting — saved reports
 * and schedules; the public API with scoped keys and webhooks; accounting exports (QuickBooks, Xero, Sage).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Laravel's database notifications: the bell in the back office.
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->uuidMorphs('notifiable');
            $t->jsonb('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });

        // How SMS and WhatsApp leave this server: one provider per channel, credentials encrypted with this server's key.
        // Node-local (never synced): each server that sends messages has its own.
        Schema::create('message_providers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('channel', 10); // sms, whatsapp
            $t->string('provider', 12); // hubtel, arkesel, mnotify, webhook, meta
            $t->string('sender_id', 20)->nullable(); // registered SMS sender name
            $t->text('credentials')->nullable(); // encrypted JSON
            $t->boolean('is_active')->default(false);
            $t->timestamp('tested_at')->nullable();
            $t->string('test_result')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'channel']);
        });

        // Wording of every message, per channel, editable in the back office ({placeholders} are filled in).
        Schema::create('message_templates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('key', 40); // receipt, reservation_booked, appointment_reminder, invoice_issued…
            $t->string('channel', 10); // sms, whatsapp, email, in_app
            $t->string('subject')->nullable();
            $t->text('body');
            $t->string('whatsapp_template', 80)->nullable(); // the Meta-approved template name
            $t->string('whatsapp_language', 10)->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'key', 'channel']);
        });

        // Every SMS, WhatsApp and e-mail: what, to whom, how it went. Append-only apart from delivery status.
        Schema::create('messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('channel', 10);
            $t->string('to', 160);
            $t->string('template_key', 40)->nullable();
            $t->string('subject')->nullable();
            $t->text('body');
            $t->jsonb('params')->default('[]'); // WhatsApp template parameters, in order
            $t->string('attachment_path')->nullable();
            $t->string('attachment_name')->nullable();
            $t->string('reference', 80)->nullable(); // receipt, booking or invoice number
            $t->string('provider', 12)->nullable();
            $t->string('status', 14); // queued, sent, failed, not_configured
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->string('provider_ref', 120)->nullable();
            $t->string('error')->nullable();
            $t->string('sent_by_name', 120);
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'status', 'next_attempt_at']);
            $t->index(['tenant_id', 'created_at']);
        });

        // Saved reports (the report builder) and their schedules.
        Schema::create('saved_reports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->string('dataset', 30);
            $t->jsonb('definition'); // columns, group_by, filters, period, sort, limit
            $t->uuid('owner_id');
            $t->string('owner_name', 120);
            $t->boolean('shared')->default(false); // others with access to the data may run it
            $t->string('schedule', 10)->default('none'); // none, daily, weekly, monthly
            $t->unsignedTinyInteger('schedule_day')->nullable(); // weekday 1–7 or day of month 1–28
            $t->string('schedule_time', 5)->nullable(); // HH:MM local
            $t->string('format', 5)->default('pdf'); // pdf, csv, xlsx
            $t->jsonb('recipients')->default('[]'); // [{type: email|whatsapp|user, to}]
            $t->timestamp('next_run_at')->nullable();
            $t->timestamp('last_run_at')->nullable();
            $t->string('last_status')->nullable();
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['schedule', 'next_run_at']);
        });

        // Systems that use the public API: their keys (Sanctum tokens) carry the scopes they may use.
        Schema::create('api_clients', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->jsonb('scopes')->default('[]');
            $t->uuid('user_id'); // acts as this person: their permissions and branches apply on top of the scopes
            $t->uuid('branch_id')->nullable(); // limit to one branch (null = all)
            $t->boolean('is_active')->default(true);
            $t->timestamp('last_used_at')->nullable();
            $t->string('created_by_name', 120);
            $t->timestamps();
        });

        Schema::create('webhook_endpoints', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->uuid('api_client_id')->nullable();
            $t->string('url', 500);
            $t->jsonb('events')->default('[]');
            $t->text('secret'); // encrypted; signs every delivery
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('failures')->default(0); // consecutive; switched off after 50
            $t->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $t->string('event', 60);
            $t->uuid('event_id'); // the same id on every retry: receivers de-duplicate on it
            $t->jsonb('payload');
            $t->string('status', 10); // pending, sent, failed, dead
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->unsignedSmallInteger('response_code')->nullable();
            $t->string('error')->nullable();
            $t->timestamps();
            $t->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        foreach (['webhook_deliveries', 'webhook_endpoints', 'api_clients', 'saved_reports', 'messages', 'message_templates', 'message_providers', 'notifications'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
