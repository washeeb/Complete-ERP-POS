<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The SyncLytics licence generator (vendor side, used only on the licence server): customers, plans and add-ons,
// licences with their package, dates and price, invoices and payments, and the renewal reminders sent.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_customers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 20)->unique();
            $t->string('name');
            $t->string('legal_name')->nullable(); // on contracts and invoices
            $t->string('contact_name', 120)->nullable();
            $t->string('email')->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('whatsapp', 30)->nullable();
            $t->string('address')->nullable();
            $t->string('tin', 40)->nullable();
            $t->string('industry', 60)->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('vendor_plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 20)->unique();
            $t->string('name', 80);
            $t->string('description', 500)->nullable();
            $t->unsignedInteger('branches')->nullable(); // null = unlimited
            $t->unsignedInteger('devices')->nullable();
            $t->unsignedInteger('users')->nullable();
            $t->jsonb('modules')->nullable(); // null = every industry module
            $t->jsonb('features')->nullable(); // null = every feature (finance, hr)
            $t->unsignedSmallInteger('months')->default(12); // licence length
            $t->bigInteger('price_minor')->default(0); // per licence period
            $t->string('currency', 3)->default('GHS');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('vendor_addons', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 20)->unique();
            $t->string('name', 80);
            $t->unsignedInteger('branches')->default(0); // added per unit
            $t->unsignedInteger('devices')->default(0);
            $t->unsignedInteger('users')->default(0);
            $t->jsonb('modules')->nullable();
            $t->jsonb('features')->nullable();
            $t->bigInteger('price_minor')->default(0); // per unit per licence period
            $t->string('currency', 3)->default('GHS');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::table('vendor_licenses', function (Blueprint $t) {
            $t->foreignUuid('customer_id')->nullable()->index()->constrained('vendor_customers')->restrictOnDelete();
            $t->foreignUuid('plan_id')->nullable()->index()->constrained('vendor_plans')->restrictOnDelete();
            $t->jsonb('addons')->nullable(); // [{addon_id, code, name, qty}]
            $t->string('kind', 12)->default('paid'); // paid | trial | internal
            $t->string('site', 120)->nullable(); // which server or shop, e.g. "Cloud server"
            $t->date('starts_on')->nullable();
            $t->date('expires_on')->nullable();
            $t->bigInteger('price_minor')->nullable();
            $t->string('currency', 3)->nullable();
            $t->uuid('replaces')->nullable()->index(); // the licence this one renews or changes
            $t->string('request_code', 600)->nullable();
            $t->text('notes')->nullable();
            $t->string('revoked_by')->nullable();
            $t->timestamp('last_checkin_at')->nullable();
            $t->index('expires_on');
        });
        Schema::create('vendor_invoices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number', 30)->unique();
            $t->foreignUuid('customer_id')->index()->constrained('vendor_customers')->restrictOnDelete();
            $t->uuid('license_id')->nullable()->index();
            $t->date('issued_on');
            $t->date('due_on');
            $t->jsonb('lines'); // [{description, qty, unit_minor, total_minor}]
            $t->bigInteger('total_minor');
            $t->string('currency', 3);
            $t->string('status', 12)->default('open'); // open | paid | void
            $t->string('void_reason')->nullable();
            $t->string('notes', 500)->nullable();
            $t->string('created_by');
            $t->timestamps();
        });
        Schema::create('vendor_payments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('invoice_id')->index()->constrained('vendor_invoices')->restrictOnDelete();
            $t->foreignUuid('customer_id')->index()->constrained('vendor_customers')->restrictOnDelete();
            $t->date('received_on');
            $t->bigInteger('amount_minor');
            $t->string('method', 20); // bank | momo | telecel | cash | card
            $t->string('reference', 120)->nullable();
            $t->string('recorded_by');
            $t->timestamps();
        });
        Schema::create('vendor_reminders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('license_id')->index();
            $t->string('kind', 20); // 60 | 30 | 7 | expired
            $t->string('channels', 60); // email, whatsapp, staff
            $t->timestamp('sent_at');
            $t->unique(['license_id', 'kind']);
        });

        // Licences issued before: dates from their signed payload.
        foreach (DB::table('vendor_licenses')->get(['id', 'payload', 'created_at']) as $l) {
            $p = json_decode($l->payload, true) ?: [];
            DB::table('vendor_licenses')->where('id', $l->id)->update(['starts_on' => substr((string) ($p['issued_at'] ?? $l->created_at), 0, 10),
                'expires_on' => isset($p['expires_at']) ? substr($p['expires_at'], 0, 10) : null, 'kind' => ! empty($p['dev']) ? 'internal' : 'paid']);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_reminders');
        Schema::dropIfExists('vendor_payments');
        Schema::dropIfExists('vendor_invoices');
        Schema::table('vendor_licenses', function (Blueprint $t) {
            $t->dropConstrainedForeignId('customer_id');
            $t->dropConstrainedForeignId('plan_id');
            $t->dropColumn(['addons', 'kind', 'site', 'starts_on', 'expires_on', 'price_minor', 'currency', 'replaces', 'request_code', 'notes', 'revoked_by', 'last_checkin_at']);
        });
        Schema::dropIfExists('vendor_addons');
        Schema::dropIfExists('vendor_plans');
        Schema::dropIfExists('vendor_customers');
    }
};
