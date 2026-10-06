<?php

use App\Domain\Finance\ChartTemplate;
use App\Domain\Hr\HrBaseline;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8: employees, attendance (clock in/out on the tills), leave, staff loans and advances, and monthly payroll
 * on a seeded Ghana profile (SSNIT tiers, PAYE bands) that an accountant must verify. People data and payroll live at
 * head office; clock events are recorded wherever the till is and flow up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('code', 20);
            $t->string('name');
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('employees', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('user_id')->nullable()->unique()->constrained()->nullOnDelete(); // their sign-in, for clocking and self-service
            $t->string('name');
            $t->string('gender', 10)->nullable();
            $t->date('date_of_birth')->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            $t->string('address')->nullable();
            $t->string('ghana_card_no', 30)->nullable(); // also the TIN for individuals
            $t->string('ssnit_no', 30)->nullable();
            $t->foreignUuid('department_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->string('job_title')->nullable();
            $t->string('employment_type', 12)->default('permanent'); // permanent, contract, casual
            $t->date('hire_date');
            $t->date('contract_end')->nullable();
            $t->date('termination_date')->nullable();
            $t->string('termination_reason')->nullable();
            $t->string('status', 12)->default('active'); // active, terminated
            $t->string('pay_method', 8)->default('bank'); // bank, momo, cash
            $t->string('bank_name')->nullable();
            $t->string('bank_account_no', 40)->nullable();
            $t->string('momo_number', 20)->nullable();
            $t->bigInteger('basic_salary_minor')->default(0); // per month
            $t->bigInteger('transport_allowance_minor')->default(0);
            $t->bigInteger('meal_allowance_minor')->default(0);
            $t->unsignedInteger('overtime_rate_bp')->default(15000); // 1.5 × the hourly rate
            $t->boolean('overtime_junior')->default(false); // qualifying junior staff: overtime taxed separately at 5 % / 10 %
            $t->unsignedInteger('tier3_bp')->default(0); // voluntary provident fund, % of basic
            $t->bigInteger('welfare_minor')->default(0);
            $t->boolean('ssnit_exempt')->default(false);
            $t->text('notes')->nullable();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
        });

        Schema::create('attendance_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->uuid('device_id')->nullable();
            $t->string('kind', 4); // in, out
            $t->timestamp('occurred_at', 6);
            $t->string('source', 10); // till, offline, manual
            $t->uuid('voids_id')->nullable(); // a correction cancelling an earlier event
            $t->string('reason')->nullable();
            $t->string('recorded_by_name');
            $t->string('origin_node', 20)->nullable();
            $t->timestamp('received_at', 6);
            $t->index(['user_id', 'occurred_at']);
        });
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER attendance_events_append_only BEFORE UPDATE OR DELETE ON attendance_events FOR EACH ROW EXECUTE FUNCTION ledger_append_only();
        SQL);

        Schema::create('leave_types', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('code', 20);
            $t->string('name');
            $t->unsignedSmallInteger('days_per_year')->nullable(); // working days; null = no fixed allowance (e.g. sick leave)
            $t->boolean('paid')->default(true);
            $t->boolean('needs_document')->default(false);
            $t->boolean('is_active')->default(true);
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('leave_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('employee_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('leave_type_id')->constrained()->restrictOnDelete();
            $t->date('starts_on');
            $t->date('ends_on');
            $t->unsignedSmallInteger('days'); // working days (Mon–Fri)
            $t->string('reason')->nullable();
            $t->string('document_path')->nullable();
            $t->string('status', 12); // requested, approved, rejected, cancelled
            $t->string('requested_by_name');
            $t->string('decided_by_name')->nullable();
            $t->uuid('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note')->nullable();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['employee_id', 'starts_on']);
        });

        Schema::create('employee_loans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('employee_id')->constrained()->restrictOnDelete();
            $t->string('kind', 10); // loan, advance
            $t->bigInteger('principal_minor');
            $t->bigInteger('monthly_minor');
            $t->date('first_month'); // first payroll month it is deducted
            $t->foreignUuid('paid_from_account_id')->constrained('accounts')->restrictOnDelete();
            $t->date('issued_on');
            $t->string('note')->nullable();
            $t->string('issued_by_name');
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
        });

        Schema::create('payroll_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('code', 20);
            $t->string('name');
            $t->date('effective_from');
            $t->jsonb('rules');
            $t->string('verified_by_name')->nullable(); // the accountant who checked it against GRA / SSNIT rules
            $t->timestamp('verified_at')->nullable();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('payroll_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->unsignedSmallInteger('year');
            $t->unsignedTinyInteger('month');
            $t->date('period_start');
            $t->date('period_end');
            $t->date('pay_date');
            $t->foreignUuid('payroll_profile_id')->constrained()->restrictOnDelete();
            $t->string('status', 10); // draft, approved, paid
            $t->jsonb('totals')->nullable();
            $t->string('prepared_by_name');
            $t->uuid('prepared_by')->nullable();
            $t->string('approved_by_name')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->foreignUuid('bank_account_id')->nullable()->constrained('accounts')->restrictOnDelete(); // net pay for bank and MoMo staff
            $t->foreignUuid('cash_account_id')->nullable()->constrained('accounts')->restrictOnDelete(); // net pay handed over in cash
            $t->date('paid_on')->nullable();
            $t->string('paid_by_name')->nullable();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'year', 'month']);
            $t->unique(['tenant_id', 'number']);
        });

        Schema::create('payslips', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('payroll_run_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('employee_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->jsonb('employee'); // snapshot: name, number, job, department, SSNIT no., Ghana Card, pay method and account
            $t->bigInteger('basic_minor');
            $t->bigInteger('gross_minor');
            $t->bigInteger('taxable_minor');
            $t->bigInteger('paye_minor'); // including separately taxed overtime
            $t->bigInteger('ssnit_employee_minor');
            $t->bigInteger('ssnit_employer_minor');
            $t->bigInteger('tier1_minor');
            $t->bigInteger('tier2_minor');
            $t->bigInteger('tier3_minor');
            $t->bigInteger('welfare_minor');
            $t->bigInteger('loans_minor');
            $t->bigInteger('net_minor');
            $t->decimal('overtime_hours', 7, 2)->default(0);
            $t->unsignedSmallInteger('days_worked')->default(0);
            $t->unsignedSmallInteger('unpaid_leave_days')->default(0);
            $t->jsonb('lines'); // [{code, label, kind: earning|deduction|employer, amount_minor}]
            $t->jsonb('loan_deductions'); // [{loan_id, amount_minor}]
            $t->timestamps();
            $t->unique(['payroll_run_id', 'employee_id']);
        });

        Schema::create('loan_repayments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('employee_loan_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('payslip_id')->constrained()->restrictOnDelete();
            $t->bigInteger('amount_minor');
            $t->timestamp('created_at');
            $t->unique(['employee_loan_id', 'payslip_id']);
        });

        // One-time backfill: payroll accounts and the seeded Ghana profile / leave types for existing companies.
        if (config('erp.node.role') !== 'branch') {
            foreach (Tenant::all() as $tenant) {
                ChartTemplate::ensure($tenant);
                HrBaseline::ensure($tenant);
            }
        }
    }

    public function down(): void
    {
        foreach (['loan_repayments', 'payslips', 'payroll_runs', 'payroll_profiles', 'employee_loans', 'leave_requests', 'leave_types'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::unprepared('DROP TABLE IF EXISTS attendance_events');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('departments');
    }
};
