<?php

use App\Domain\Finance\ChartTemplate;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Expired and near-expiry stock: disposals (a stock adjustment of kind "disposal", with its method, witness and
// certificate, always approved by a second person), returns to suppliers (their own document, settled by credit note,
// replacement, refund or nothing), each supplier's return window, and the account that holds returned goods until the
// supplier settles.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $t) {
            $t->string('kind', 20)->default('adjustment'); // adjustment | disposal
            $t->string('disposal_method', 40)->nullable();
            $t->date('disposed_on')->nullable();
            $t->string('witness_name', 120)->nullable();
            $t->string('certificate_path', 255)->nullable();
        });
        Schema::table('suppliers', fn (Blueprint $t) => $t->unsignedSmallInteger('return_days_before_expiry')->nullable());

        Schema::create('supplier_returns', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->index()->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('branch_id')->index()->constrained()->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->index()->constrained()->restrictOnDelete();
            $t->foreignUuid('supplier_id')->index()->constrained()->restrictOnDelete();
            $t->string('status', 20)->default('submitted'); // submitted | posted | cancelled
            $t->string('settlement', 20);                   // credit_note | replacement | refund | none
            $t->string('reason', 20)->default('expired');   // expired | near_expiry | damaged | recalled | other
            $t->string('note', 500)->nullable();
            $t->bigInteger('total_minor')->default(0);
            $t->foreignUuid('prepared_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $t->string('prepared_by_name', 120);
            $t->foreignUuid('approved_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $t->string('approved_by_name', 120)->nullable();
            $t->timestamp('posted_at')->nullable();
            $t->string('settlement_ref', 120)->nullable();     // the supplier's credit note, the replacement delivery, the refund receipt
            $t->bigInteger('refund_minor')->nullable();
            $t->foreignUuid('refund_account_id')->nullable()->index()->constrained('accounts')->restrictOnDelete(); // the cash / MoMo / bank account the refund went into
            $t->timestamp('settled_at')->nullable();
            $t->string('settled_by_name', 120)->nullable();
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
        });
        Schema::create('supplier_return_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('supplier_return_id')->index()->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->foreignUuid('product_id')->index()->constrained()->restrictOnDelete();
            $t->uuid('batch_id')->nullable()->index();
            $t->string('batch_no', 60)->nullable();
            $t->date('expires_on')->nullable();
            $t->decimal('qty', 18, 3);       // base units
            $t->bigInteger('value_minor')->default(0); // at average cost when posted
            $t->timestamps();
        });

        foreach (Tenant::all() as $tenant) {
            ChartTemplate::ensure($tenant); // the "goods returned to suppliers" account
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_return_lines');
        Schema::dropIfExists('supplier_returns');
        Schema::table('suppliers', fn (Blueprint $t) => $t->dropColumn('return_days_before_expiry'));
        Schema::table('stock_adjustments', fn (Blueprint $t) => $t->dropColumn(['kind', 'disposal_method', 'disposed_on', 'witness_name', 'certificate_path']));
    }
};
