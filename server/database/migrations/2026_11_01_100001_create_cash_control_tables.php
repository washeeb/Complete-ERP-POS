<?php

use App\Domain\Finance\ChartTemplate;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Cash control (owner decisions 2026-10-12): shifts (Morning / Afternoon, configurable), one vault (cash tray with its
 * own float) per till and shift, and the chain Teller → Cashier → Income Controller with blind counts at every step.
 * Differences are approved by the Income Controller as cash over/short or charged to the staff member.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_templates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('name', 40);
            $t->time('starts_at');
            $t->time('ends_at');
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'name']);
        });

        Schema::create('vaults', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('till_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('shift_template_id')->constrained()->restrictOnDelete();
            $t->string('name', 80);
            $t->bigInteger('standard_float_minor')->default(0);
            $t->boolean('is_active')->default(true);
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['till_id', 'shift_template_id']);
        });

        // One working day of one vault: float issued → teller's shift → teller's blind count → cashier's blind count →
        // handed to the income controller.
        Schema::create('vault_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('till_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('vault_id')->constrained()->restrictOnDelete();
            $t->date('business_date');
            $t->string('status', 20); // issued, open, counted, received, handed_over, cancelled
            $t->foreignUuid('teller_id')->constrained('users')->restrictOnDelete();
            $t->string('teller_name');
            $t->bigInteger('float_minor');
            $t->foreignUuid('issued_by')->constrained('users')->restrictOnDelete();
            $t->string('issued_by_name');
            $t->timestampTz('issued_at');
            $t->foreignUuid('shift_id')->nullable()->unique();
            $t->bigInteger('expected_minor')->nullable();
            $t->bigInteger('teller_count_minor')->nullable();
            $t->jsonb('teller_denoms')->nullable();
            $t->timestampTz('counted_at')->nullable();
            $t->foreignUuid('cashier_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('cashier_name')->nullable();
            $t->bigInteger('cashier_count_minor')->nullable();
            $t->jsonb('cashier_denoms')->nullable();
            $t->unsignedSmallInteger('cashier_recounts')->default(0);
            $t->timestampTz('received_at')->nullable();
            $t->foreignUuid('handover_id')->nullable();
            $t->string('note')->nullable();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['vault_id', 'business_date']);
            $t->index(['branch_id', 'business_date', 'status']);
        });

        // The cashier's day handed to the income controller, counted blind again.
        Schema::create('cash_handovers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->date('business_date');
            $t->string('status', 20); // handed_over, received
            $t->foreignUuid('cashier_id')->constrained('users')->restrictOnDelete();
            $t->string('cashier_name');
            $t->bigInteger('declared_minor'); // what the cashier received from the tellers (their own counts)
            $t->timestampTz('handed_at');
            $t->foreignUuid('controller_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('controller_name')->nullable();
            $t->bigInteger('controller_count_minor')->nullable();
            $t->jsonb('controller_denoms')->nullable();
            $t->timestampTz('received_at')->nullable();
            $t->foreignUuid('deposit_transfer_id')->nullable(); // money_transfers row for the bank deposit
            $t->string('note')->nullable();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['branch_id', 'status']);
        });

        // A difference found at a count, owned by the person responsible, until the income controller decides.
        Schema::create('cash_variances', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->string('stage', 20); // teller (vault vs till expected), cashier (handover vs declared)
            $t->foreignUuid('vault_session_id')->nullable();
            $t->foreignUuid('handover_id')->nullable();
            $t->foreignUuid('person_id')->constrained('users')->restrictOnDelete();
            $t->string('person_name');
            $t->bigInteger('amount_minor'); // counted − expected: negative = short, positive = over
            $t->string('status', 20)->default('open'); // open, approved
            $t->string('treatment', 20)->nullable(); // over_short, charge_staff
            $t->string('reason')->nullable();
            $t->string('decided_by_name')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->foreignUuid('employee_loan_id')->nullable();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['branch_id', 'status']);
        });

        // Every move of cash between the books' cash accounts, append-only; the auto-poster posts each one.
        Schema::create('cash_movements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->string('kind', 30); // float_issue, vault_receive, vault_difference, handover_receive, handover_difference, variance_over_short
            $t->string('from_account', 40); // system account keys
            $t->string('to_account', 40);
            $t->bigInteger('amount_minor');
            $t->string('source_type', 40);
            $t->uuid('source_id');
            $t->string('party_type', 20)->nullable();
            $t->uuid('party_id')->nullable();
            $t->string('memo');
            $t->timestampTz('occurred_at');
            $t->string('origin_node', 20)->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['tenant_id', 'occurred_at']);
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cash_movements_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'cash_movements is append-only; record a correcting movement instead';
            END $$;
            CREATE TRIGGER cash_movements_no_update BEFORE UPDATE OR DELETE ON cash_movements
                FOR EACH ROW EXECUTE FUNCTION cash_movements_immutable();
            CREATE TRIGGER cash_movements_no_truncate BEFORE TRUNCATE ON cash_movements
                FOR EACH STATEMENT EXECUTE FUNCTION cash_movements_immutable();
        SQL);

        Schema::table('shifts', function (Blueprint $t) {
            $t->foreignUuid('vault_session_id')->nullable()->unique();
            $t->jsonb('counted_denoms')->nullable(); // the teller's count by note and coin
        });

        // Today's "Cashier" role (sells at a till) becomes "Teller"; the new Cashier role (receives the tellers' cash) is
        // added by the role catalogue when the seeders run. Existing grants keep working: they point at the role row.
        DB::table('roles')->where('key', 'cashier')->where('is_system', true)
            ->update(['key' => 'teller', 'name' => 'Teller', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);

        // Backfill: the two default shifts for existing companies, and the new cash accounts.
        foreach (Tenant::query()->withoutGlobalScopes()->get() as $tenant) {
            foreach ([['Morning', '07:00', '14:00', 1], ['Afternoon', '14:00', '21:00', 2]] as [$name, $from, $to, $sort]) {
                if (! DB::table('shift_templates')->where('tenant_id', $tenant->id)->where('name', $name)->exists()) {
                    DB::table('shift_templates')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'name' => $name,
                        'starts_at' => $from, 'ends_at' => $to, 'sort' => $sort, 'is_active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            ChartTemplate::ensure($tenant);
        }
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $t) {
            $t->dropColumn(['vault_session_id', 'counted_denoms']);
        });
        DB::unprepared('DROP TRIGGER IF EXISTS cash_movements_no_update ON cash_movements; DROP TRIGGER IF EXISTS cash_movements_no_truncate ON cash_movements; DROP FUNCTION IF EXISTS cash_movements_immutable();');
        foreach (['cash_movements', 'cash_variances', 'cash_handovers', 'vault_sessions', 'vaults', 'shift_templates'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
