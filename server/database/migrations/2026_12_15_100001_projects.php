<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Projects and job costing (interface stage 3; paid add-on "projects"). A project for a customer (billed by milestones
 * or for time and materials) or internal (not billed), with tasks, milestones and staff time from approved weekly
 * timesheets. Its costs are collected automatically into project_costs — stock issued to it, supplier bills and
 * expense claims charged to it, approved time — and billed from there. The books carry the project on each journal line;
 * a billed project's costs wait in "Project work in progress" until invoiced (project_billings move them to cost).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 30);
            $t->string('name');
            $t->foreignUuid('customer_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('billing', 10);                  // milestones | time | internal
            $t->string('status', 10)->default('active'); // planned | active | on_hold | done | cancelled
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->bigInteger('contract_minor')->nullable();   // agreed price (milestones)
            $t->bigInteger('budget_cost_minor')->nullable(); // what it should cost
            $t->bigInteger('hourly_rate_minor')->nullable(); // time billed at (when the person has no rate of their own)
            $t->unsignedInteger('materials_markup_bp')->default(0); // materials billed at cost + this
            $t->foreignUuid('service_product_id')->nullable()->constrained('products')->restrictOnDelete(); // invoice lines for time, milestones, bought-in costs
            $t->foreignUuid('crm_deal_id')->nullable()->constrained('crm_deals')->nullOnDelete();
            $t->char('currency_code', 3);
            $t->text('description')->nullable();
            $t->string('created_by_name')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'status']);
            $t->index('customer_id');
            $t->index('branch_id');
            $t->index('manager_id');
            $t->index('service_product_id');
            $t->index('crm_deal_id');
        });

        Schema::create('project_tasks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->foreignUuid('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status', 8)->default('todo'); // todo | doing | done
            $t->date('due_on')->nullable();
            $t->decimal('estimate_hours', 8, 2)->nullable();
            $t->boolean('billable')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index('project_id');
            $t->index('assigned_to');
            $t->index('tenant_id');
        });

        Schema::create('project_milestones', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->bigInteger('amount_minor');
            $t->date('due_on')->nullable();
            $t->string('status', 10)->default('pending'); // pending | ready | invoiced
            $t->foreignUuid('invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index('project_id');
            $t->index('invoice_id');
            $t->index('tenant_id');
        });

        // What an hour of each person costs the company and is billed at (company-wide; a project's rate fills gaps).
        Schema::create('project_rates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->bigInteger('cost_rate_minor')->default(0);
            $t->bigInteger('bill_rate_minor')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'user_id']);
            $t->index('user_id');
        });

        Schema::create('time_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('task_id')->nullable()->constrained('project_tasks')->nullOnDelete();
            $t->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $t->date('work_date');
            $t->unsignedInteger('minutes')->default(0);
            $t->timestamp('timer_started_at')->nullable(); // a running timer
            $t->string('note', 255)->nullable();
            $t->boolean('billable')->default(true);
            $t->string('status', 10)->default('draft'); // draft | submitted | approved | rejected
            $t->string('reject_reason', 255)->nullable();
            $t->string('decided_by_name')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['user_id', 'work_date']);
            $t->index(['tenant_id', 'status']);
            $t->index('project_id');
            $t->index('task_id');
        });

        Schema::create('project_issues', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 30);
            $t->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->string('kind', 8)->default('issue'); // issue | return
            $t->string('note', 255)->nullable();
            $t->bigInteger('value_minor')->default(0);
            $t->string('created_by_name')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index('project_id');
            $t->index('warehouse_id');
            $t->index('branch_id');
        });

        // Every cost a project collects (append-only; billing and release only mark rows).
        Schema::create('project_costs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $t->string('kind', 10);                  // material | purchase | expense | time
            $t->string('source_type', 30);
            $t->uuid('source_id');
            $t->foreignUuid('product_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('product_uom_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('description', 255);
            $t->decimal('qty', 14, 3)->default(1);   // pieces, or hours for time
            $t->bigInteger('cost_minor');            // negative for a return
            $t->bigInteger('bill_minor')->default(0); // what it is billed at (time: hours × rate; others: cost + markup)
            $t->boolean('billable')->default(true);
            $t->boolean('in_books')->default(true);  // false for time: salaries are already in the books
            $t->date('occurred_on');
            $t->foreignUuid('invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('released_at')->nullable(); // moved from work in progress to cost
            $t->timestamps();
            $t->unique(['source_type', 'source_id', 'kind']);
            $t->index(['project_id', 'kind']);
            $t->index('product_id');
            $t->index('product_uom_id');
            $t->index('invoice_id');
            $t->index('tenant_id');
        });

        // The books: work in progress moved to project cost when a project is invoiced or closed (posted automatically).
        Schema::create('project_billings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('reason', 10);                // invoice | close
            $t->bigInteger('released_minor');
            $t->date('released_on');
            $t->timestamps();
            $t->index('project_id');
            $t->index('branch_id');
            $t->index('invoice_id');
            $t->index('tenant_id');
        });

        foreach (['journal_lines', 'invoices', 'purchase_order_lines', 'supplier_bill_lines', 'expense_claims'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();
                $t->index('project_id');
            });
        }

        // "Projects & Tasks" was a planned module branches could reserve; it is now this licence add-on instead.
        DB::table('branch_modules')->where('module_key', 'projects')->where('status', 'reserved')->delete();
    }

    public function down(): void
    {
        foreach (['journal_lines', 'invoices', 'purchase_order_lines', 'supplier_bill_lines', 'expense_claims'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('project_id'));
        }
        Schema::dropIfExists('project_billings');
        Schema::dropIfExists('project_costs');
        Schema::dropIfExists('project_issues');
        Schema::dropIfExists('time_entries');
        Schema::dropIfExists('project_rates');
        Schema::dropIfExists('project_milestones');
        Schema::dropIfExists('project_tasks');
        Schema::dropIfExists('projects');
    }
};
