<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 — pilot and launch (owner decisions 2026-10-30): data migration from spreadsheets, QuickBooks or another till
 * system (or a fresh start); a go-live plan with three cut-over styles (parallel run by default, big-bang, one till
 * first) and three pilot styles (one shop by default, several shops, an internal dry run); a practice company for
 * training; problem reports from staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A practice company: same server, its own made-up books; nothing it does reaches GRA, customers or other systems.
        Schema::table('tenants', function (Blueprint $t) {
            $t->boolean('is_practice')->default(false);
            $t->uuid('practice_of')->nullable(); // the real company it was copied from
        });

        // One go-live plan per company: where the data comes from, how the switch-over happens, and the pilot.
        Schema::create('go_live_plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->unique()->constrained()->restrictOnDelete();
            $t->jsonb('sources')->default('["excel"]'); // excel, quickbooks, other_pos, fresh
            $t->string('cutover_mode', 12)->default('parallel'); // parallel, big_bang, one_till
            $t->string('pilot_mode', 12)->default('one_shop'); // one_shop, several, dry_run
            $t->string('status', 12)->default('planning'); // planning, parallel, live, rolled_back
            $t->date('go_live_on')->nullable(); // the date the opening balances are "as of"
            $t->date('parallel_until')->nullable();
            $t->jsonb('pilot_branch_ids')->default('[]');
            $t->jsonb('steps')->default('{}'); // step key => {done_at, by, skipped, note}
            $t->jsonb('old_totals')->default('{}'); // the old system's figures, to compare with what was loaded
            $t->uuid('snapshot_backup_id')->nullable(); // the backup taken just before going live
            $t->string('support_email', 160)->nullable(); // where problem reports and the daily pilot report go
            $t->string('pilot_report_time', 5)->default('19:00');
            $t->date('pilot_report_sent_on')->nullable();
            $t->timestamp('live_at')->nullable();
            $t->string('rollback_reason')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        // Parallel run: the old system's daily totals next to this system's, per branch and day.
        Schema::create('parallel_days', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->date('day');
            $t->bigInteger('old_sales_minor');
            $t->bigInteger('old_cash_minor')->nullable();
            $t->integer('old_receipts')->nullable();
            $t->bigInteger('new_sales_minor')->default(0);
            $t->bigInteger('new_cash_minor')->default(0);
            $t->integer('new_receipts')->default(0);
            $t->string('note')->nullable();
            $t->string('entered_by_name', 120);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'branch_id', 'day']);
        });

        // "Report a problem" from the back office or a till, with what the screen and the server knew at the time.
        Schema::create('support_reports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 20);
            $t->uuid('user_id')->nullable();
            $t->string('user_name', 120);
            $t->string('source', 12); // back_office, till
            $t->uuid('device_id')->nullable();
            $t->uuid('branch_id')->nullable();
            $t->string('page')->nullable();
            $t->text('message');
            $t->jsonb('context')->default('{}'); // app version, browser, recent errors
            $t->string('status', 10)->default('open'); // open, closed
            $t->string('resolution')->nullable();
            $t->string('closed_by_name', 120)->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->string('send_error')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_reports');
        Schema::dropIfExists('parallel_days');
        Schema::dropIfExists('go_live_plans');
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn(['is_practice', 'practice_of']));
    }
};
