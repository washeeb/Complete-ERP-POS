<?php

use App\Domain\Finance\ChartTemplate;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7b: bank / MoMo / cash accounts with statement import and reconciliation, money moved between them,
 * expense claims paid from petty cash, fixed assets with monthly depreciation, and budgets. Head office only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('account_id')->unique()->constrained()->restrictOnDelete(); // its general-ledger account
            $t->string('kind', 10); // bank, momo, cash
            $t->string('name');
            $t->string('provider')->nullable(); // bank or network
            $t->string('number', 60)->nullable();
            $t->jsonb('csv_mapping')->nullable(); // remembered statement column mapping
            $t->string('feed', 20)->default('csv'); // where statements come from (csv now; bank APIs later)
            $t->boolean('is_active')->default(true);
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        Schema::create('bank_statement_imports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('bank_account_id')->constrained()->restrictOnDelete();
            $t->string('file_name');
            $t->unsignedInteger('lines_added');
            $t->unsignedInteger('lines_skipped');
            $t->date('from_date')->nullable();
            $t->date('to_date')->nullable();
            $t->string('imported_by_name');
            $t->timestamp('created_at');
        });

        Schema::create('bank_matches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('bank_account_id')->constrained()->restrictOnDelete();
            $t->bigInteger('amount_minor');
            $t->string('matched_by_name');
            $t->timestamp('created_at');
        });

        Schema::create('bank_statement_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('bank_account_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('import_id')->nullable()->constrained('bank_statement_imports')->restrictOnDelete();
            $t->date('line_date');
            $t->string('description')->nullable();
            $t->string('reference', 120)->nullable();
            $t->bigInteger('amount_minor'); // + money in, − money out
            $t->bigInteger('balance_minor')->nullable(); // running balance printed on the statement
            $t->char('hash', 64); // same line imported twice is skipped
            $t->foreignUuid('match_id')->nullable()->constrained('bank_matches')->nullOnDelete();
            $t->string('status', 10)->default('open'); // open, matched, ignored
            $t->timestamp('created_at');
            $t->unique(['bank_account_id', 'hash']);
            $t->index(['bank_account_id', 'status', 'line_date']);
        });

        // A ledger line is cleared against the statement at most once.
        Schema::create('bank_matched_lines', function (Blueprint $t) {
            $t->foreignUuid('journal_line_id')->primary()->constrained()->restrictOnDelete();
            $t->foreignUuid('match_id')->constrained('bank_matches')->cascadeOnDelete();
        });

        Schema::create('money_transfers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('from_account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignUuid('to_account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('to_branch_id')->constrained('branches')->restrictOnDelete();
            $t->bigInteger('amount_minor');
            $t->date('moved_on');
            $t->string('reference', 80)->nullable();
            $t->string('note')->nullable();
            $t->string('moved_by_name');
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
        });

        Schema::create('expense_claims', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('claimant_id')->constrained('users')->restrictOnDelete();
            $t->string('claimant_name');
            $t->date('spent_on');
            $t->foreignUuid('account_id')->constrained()->restrictOnDelete(); // what it was for (expense account)
            $t->string('description');
            $t->string('payee')->nullable();
            $t->bigInteger('amount_minor');
            $t->string('receipt_path')->nullable();
            $t->string('status', 12); // submitted, approved, paid, rejected
            $t->string('approved_by_name')->nullable();
            $t->uuid('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->string('rejection_reason')->nullable();
            $t->foreignUuid('paid_from_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $t->date('paid_on')->nullable();
            $t->string('paid_by_name')->nullable();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['status', 'branch_id']);
        });

        Schema::create('asset_classes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('code', 20);
            $t->string('name');
            $t->unsignedSmallInteger('life_months');
            $t->foreignUuid('cost_account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignUuid('accumulated_account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignUuid('expense_account_id')->constrained('accounts')->restrictOnDelete();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('fixed_assets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->string('name');
            $t->foreignUuid('asset_class_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->string('serial_no', 80)->nullable();
            $t->string('location')->nullable();
            $t->date('acquired_on');
            $t->bigInteger('cost_minor');
            $t->bigInteger('residual_minor')->default(0);
            $t->unsignedSmallInteger('life_months');
            $t->bigInteger('opening_depreciation_minor')->default(0); // already depreciated before go-live (in the opening balances)
            $t->string('status', 10)->default('active'); // active, disposed
            $t->date('disposed_on')->nullable();
            $t->bigInteger('disposal_proceeds_minor')->nullable();
            $t->foreignUuid('proceeds_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $t->string('disposal_reason')->nullable();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
        });

        Schema::create('asset_depreciation', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('fixed_asset_id')->constrained()->restrictOnDelete();
            $t->date('month'); // first day of the month depreciated
            $t->bigInteger('amount_minor');
            $t->timestamp('created_at');
            $t->unique(['fixed_asset_id', 'month']);
        });

        Schema::create('budget_amounts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('year'); // financial year
            $t->unsignedTinyInteger('period'); // 1..12
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('account_id')->constrained()->restrictOnDelete();
            $t->bigInteger('amount_minor'); // income and expense both entered as positive amounts
            $t->timestamps();
            $t->unique(['tenant_id', 'year', 'period', 'branch_id', 'account_id']);
        });

        // One-time backfill: companies set up in 7a get the new purposes (petty cash, depreciation…) and asset classes.
        if (config('erp.node.role') !== 'branch') {
            foreach (Tenant::all() as $tenant) {
                ChartTemplate::ensure($tenant);
            }
        }
    }

    public function down(): void
    {
        foreach (['budget_amounts', 'asset_depreciation', 'fixed_assets', 'asset_classes', 'expense_claims', 'money_transfers', 'bank_matched_lines',
            'bank_statement_lines', 'bank_matches', 'bank_statement_imports', 'bank_accounts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
