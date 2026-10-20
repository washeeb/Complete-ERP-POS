<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What happens to a till's drawer besides sales: expenses paid out of it, cash added (pay-in), cash taken to the safe
 * (cash drop), the drawer opened without a sale, and the cash-limit warning. Append-only, like every ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('till_drawer_events', function (Blueprint $t) {
            $t->uuid('id')->primary(); // from the till, so an offline event replays safely
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('till_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('device_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('shift_id')->constrained()->restrictOnDelete();
            $t->string('kind', 20); // payout | payin | drop | no_sale | limit_alert
            $t->bigInteger('amount_minor')->default(0);
            $t->foreignUuid('account_id')->nullable()->constrained()->restrictOnDelete(); // a pay-out's expense account
            $t->string('payee')->nullable();
            $t->string('reason')->nullable();
            $t->string('reference', 80)->nullable(); // the supplier's receipt number
            $t->string('receipt_path')->nullable(); // photo of the receipt
            $t->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $t->string('user_name');
            $t->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('approved_by_name')->nullable();
            $t->foreignUuid('expense_claim_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('cash_movement_id')->nullable()->constrained()->restrictOnDelete();
            $t->boolean('offline')->default(false);
            $t->timestampTz('occurred_at');
            $t->string('origin_node', 20)->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->index('tenant_id');
            $t->index('branch_id');
            $t->index('till_id');
            $t->index('device_id');
            $t->index(['shift_id', 'kind']);
            $t->index('account_id');
            $t->index('user_id');
            $t->index('approved_by');
            $t->index('expense_claim_id');
            $t->index('cash_movement_id');
        });
        DB::statement('CREATE TRIGGER till_drawer_events_append_only BEFORE UPDATE OR DELETE ON till_drawer_events FOR EACH ROW EXECUTE FUNCTION ledger_append_only()');
    }

    public function down(): void
    {
        Schema::dropIfExists('till_drawer_events');
    }
};
