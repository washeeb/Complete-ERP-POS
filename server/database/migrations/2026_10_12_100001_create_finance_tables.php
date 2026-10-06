<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7a: general ledger (chart of accounts, months, journals and the link from every source document to the
 * journal that posted it) and the payables sub-ledger (supplier bills, supplier payments). Finance lives at head
 * office: none of these tables replicate to branch servers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('code', 20);
            $t->string('name');
            $t->string('type', 12); // asset, liability, equity, income, expense
            $t->uuid('parent_id')->nullable();
            $t->boolean('is_header')->default(false); // groups accounts; nothing posts to it
            $t->string('control', 20)->nullable(); // receivables, payables, inventory, stored_value, loyalty: only their sub-ledger posts here
            $t->string('system_key', 40)->nullable(); // what automatic posting uses this account for (e.g. cash_till, output_tax:VAT)
            $t->boolean('is_active')->default(true);
            $t->string('description')->nullable();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
            $t->unique(['tenant_id', 'system_key']);
        });
        Schema::table('accounts', fn (Blueprint $t) => $t->foreign('parent_id')->references('id')->on('accounts')->nullOnDelete());

        Schema::create('fiscal_periods', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('year'); // financial year (named by the calendar year it ends in)
            $t->unsignedTinyInteger('period'); // 1..12
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('status', 10)->default('open'); // open, closed
            $t->timestamp('closed_at')->nullable();
            $t->string('closed_by_name')->nullable();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'year', 'period']);
            $t->unique(['tenant_id', 'starts_on']);
        });

        Schema::create('journal_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->date('entry_date');
            $t->foreignUuid('period_id')->constrained('fiscal_periods')->restrictOnDelete();
            $t->string('kind', 12); // auto, manual, opening, reversal
            $t->string('source_type', 40)->nullable(); // what produced it (sales, stock, invoice, …) for automatic entries
            $t->string('memo');
            $t->uuid('reverses_id')->nullable();
            $t->char('currency_code', 3);
            $t->bigInteger('total_minor'); // sum of debits
            $t->string('posted_by_name');
            $t->string('origin_node', 20)->nullable();
            $t->timestamp('created_at');
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'entry_date']);
        });
        Schema::table('journal_entries', fn (Blueprint $t) => $t->foreign('reverses_id')->references('id')->on('journal_entries')->restrictOnDelete());

        Schema::create('journal_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('journal_entry_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('line_no');
            $t->foreignUuid('account_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete(); // cost centre
            $t->bigInteger('amount_minor'); // + debit, − credit
            $t->string('party_type', 20)->nullable(); // customer, supplier
            $t->uuid('party_id')->nullable();
            $t->string('memo')->nullable();
            $t->date('entry_date'); // copied from the entry for fast reports
            $t->index(['account_id', 'entry_date']);
            $t->index(['tenant_id', 'entry_date']);
            $t->index(['party_type', 'party_id']);
        });

        // Which journal posted each source row; the primary key is what makes automatic posting exactly-once.
        Schema::create('gl_postings', function (Blueprint $t) {
            $t->string('source_type', 40);
            $t->uuid('source_id');
            $t->foreignUuid('journal_entry_id')->nullable()->constrained()->restrictOnDelete(); // null: nothing to post (e.g. stock with no cost yet)
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->primary(['source_type', 'source_id']);
            $t->index('journal_entry_id');
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER journal_entries_append_only BEFORE UPDATE OR DELETE ON journal_entries FOR EACH ROW EXECUTE FUNCTION ledger_append_only();
            CREATE TRIGGER journal_entries_no_truncate BEFORE TRUNCATE ON journal_entries FOR EACH STATEMENT EXECUTE FUNCTION ledger_append_only();
            CREATE TRIGGER journal_lines_append_only BEFORE UPDATE OR DELETE ON journal_lines FOR EACH ROW EXECUTE FUNCTION ledger_append_only();
            CREATE TRIGGER journal_lines_no_truncate BEFORE TRUNCATE ON journal_lines FOR EACH STATEMENT EXECUTE FUNCTION ledger_append_only();
            CREATE TRIGGER gl_postings_append_only BEFORE UPDATE OR DELETE ON gl_postings FOR EACH ROW EXECUTE FUNCTION ledger_append_only();

            -- Nothing posts into a closed month.
            CREATE OR REPLACE FUNCTION journal_period_open() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE p RECORD;
            BEGIN
                SELECT status, starts_on, ends_on INTO p FROM fiscal_periods WHERE id = NEW.period_id;
                IF p.status <> 'open' THEN RAISE EXCEPTION 'period % is closed', p.starts_on; END IF;
                IF NEW.entry_date < p.starts_on OR NEW.entry_date > p.ends_on THEN RAISE EXCEPTION 'entry date % is outside its period', NEW.entry_date; END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER journal_entries_period_open BEFORE INSERT ON journal_entries FOR EACH ROW EXECUTE FUNCTION journal_period_open();

            -- Every entry balances, checked when the transaction commits (after all its lines are in).
            CREATE OR REPLACE FUNCTION journal_balanced() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE s bigint; n int;
            BEGIN
                SELECT COALESCE(SUM(amount_minor), 0), COUNT(*) INTO s, n FROM journal_lines WHERE journal_entry_id = NEW.journal_entry_id;
                IF s <> 0 THEN RAISE EXCEPTION 'journal entry % does not balance (%)', NEW.journal_entry_id, s; END IF;
                IF n < 2 THEN RAISE EXCEPTION 'journal entry % needs at least two lines', NEW.journal_entry_id; END IF;
                RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER journal_lines_balanced AFTER INSERT ON journal_lines DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION journal_balanced();
        SQL);

        Schema::table('tax_rates', function (Blueprint $t) {
            $t->boolean('input_recoverable')->default(true); // tax on purchases claimable against output tax (accountant to confirm)
        });

        Schema::create('supplier_bills', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->string('supplier_invoice_no', 60);
            $t->date('bill_date');
            $t->date('due_on');
            $t->char('currency_code', 3);
            $t->bigInteger('net_minor');
            $t->bigInteger('tax_minor');
            $t->bigInteger('total_minor');
            $t->jsonb('tax_breakdown');
            $t->bigInteger('variance_minor')->default(0); // billed minus received at order price (stock lines)
            $t->string('status', 20); // pending_approval, posted, rejected
            $t->string('variance_reason')->nullable();
            $t->string('created_by_name');
            $t->uuid('created_by')->nullable();
            $t->string('approved_by_name')->nullable();
            $t->timestamp('posted_at')->nullable();
            $t->text('note')->nullable();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->unique(['supplier_id', 'supplier_invoice_no']);
        });

        Schema::create('supplier_bill_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('supplier_bill_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->foreignUuid('goods_receipt_line_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('account_id')->nullable()->constrained()->restrictOnDelete(); // expense lines (no goods)
            $t->foreignUuid('product_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('description');
            $t->decimal('qty', 15, 3);
            $t->bigInteger('unit_cost_minor'); // as billed, before tax
            $t->bigInteger('order_cost_minor')->nullable(); // PO price per unit (stock lines)
            $t->bigInteger('received_value_minor')->default(0); // qty × goods-received cost: what clears goods-received-not-invoiced
            $t->bigInteger('net_minor');
            $t->bigInteger('tax_minor');
            $t->bigInteger('total_minor');
            $t->jsonb('tax_breakdown');
        });

        Schema::create('supplier_payments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('account_id')->constrained()->restrictOnDelete(); // paid from (bank, MoMo, cash)
            $t->string('method', 12);
            $t->string('reference', 80)->nullable();
            $t->char('currency_code', 3);
            $t->bigInteger('amount_minor');
            $t->date('paid_on');
            $t->string('paid_by_name');
            $t->text('note')->nullable();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
        });

        Schema::create('supplier_ledger', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('type', 20); // bill, payment, opening
            $t->bigInteger('amount_minor'); // + we owe more, − less
            $t->char('currency_code', 3);
            $t->string('doc_type', 30);
            $t->uuid('doc_id');
            $t->string('doc_number', 60);
            $t->date('due_on')->nullable();
            $t->string('actor_name');
            $t->string('origin_node', 20)->nullable();
            $t->timestamp('occurred_at', 6);
            $t->timestamp('received_at', 6);
            $t->index(['supplier_id', 'occurred_at']);
            $t->index(['doc_type', 'doc_id']);
        });
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER supplier_ledger_append_only BEFORE UPDATE OR DELETE ON supplier_ledger FOR EACH ROW EXECUTE FUNCTION ledger_append_only();
            CREATE TRIGGER supplier_ledger_no_truncate BEFORE TRUNCATE ON supplier_ledger FOR EACH STATEMENT EXECUTE FUNCTION ledger_append_only();
        SQL);

        Schema::create('supplier_balances', function (Blueprint $t) {
            $t->foreignUuid('supplier_id')->primary()->constrained()->restrictOnDelete();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->bigInteger('balance_minor')->default(0);
            $t->timestamp('updated_at')->nullable();
        });

        Schema::create('payable_allocations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $t->string('from_type', 30);
            $t->uuid('from_id');
            $t->string('to_type', 30);
            $t->uuid('to_id');
            $t->bigInteger('amount_minor');
            $t->string('origin_node', 20)->nullable();
            $t->timestamp('created_at', 6);
            $t->index(['to_type', 'to_id']);
            $t->index(['from_type', 'from_id']);
        });
    }

    public function down(): void
    {
        foreach (['payable_allocations', 'supplier_balances'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::unprepared('DROP TABLE IF EXISTS supplier_ledger');
        foreach (['supplier_payments', 'supplier_bill_lines', 'supplier_bills'] as $t) {
            Schema::dropIfExists($t);
        }
        if (Schema::hasColumn('tax_rates', 'input_recoverable')) {
            Schema::table('tax_rates', fn (Blueprint $t) => $t->dropColumn('input_recoverable'));
        }
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS gl_postings;
            DROP TABLE IF EXISTS journal_lines;
            DROP TABLE IF EXISTS journal_entries;
            DROP FUNCTION IF EXISTS journal_balanced();
            DROP FUNCTION IF EXISTS journal_period_open();
        SQL);
        Schema::dropIfExists('fiscal_periods');
        Schema::dropIfExists('accounts');
    }
};
