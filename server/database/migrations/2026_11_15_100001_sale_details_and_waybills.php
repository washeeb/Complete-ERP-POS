<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Till sales keep a comment (optionally printed), a walk-in's name and phone without creating a customer, and industry
// details (a pharmacy patient's age, sex and weight; guests; the next visit). Waybills go with goods on the move:
// deliveries to customers, transfers between branches, returns to suppliers, and the supplier's own waybill on goods received.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $t) {
            $t->string('comment', 500)->nullable();
            $t->boolean('comment_on_receipt')->default(false);
            $t->string('walk_in_name', 120)->nullable();
            $t->string('walk_in_phone', 30)->nullable()->index();
            $t->jsonb('extras')->nullable();
        });
        Schema::create('waybills', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->index()->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('branch_id')->index()->constrained()->restrictOnDelete();
            $t->string('kind', 20); // delivery | transfer | supplier_return | inbound
            $t->string('source_type', 30); // delivery | stock_transfer | supplier_return | goods_receipt
            $t->uuid('source_id')->index();
            $t->string('source_number', 40);
            $t->string('reference', 80)->nullable(); // the supplier's or transporter's own waybill number
            $t->string('from_name');
            $t->string('from_address')->nullable();
            $t->string('to_name');
            $t->string('to_address')->nullable();
            $t->string('to_phone', 30)->nullable();
            $t->string('transporter', 120)->nullable();
            $t->string('driver_name', 120)->nullable();
            $t->string('driver_phone', 30)->nullable();
            $t->string('vehicle_no', 30)->nullable();
            $t->unsignedInteger('packages')->nullable();
            $t->decimal('weight_kg', 12, 2)->nullable();
            $t->jsonb('lines'); // [{sku, name, qty, unit}] as sent
            $t->string('status', 12)->default('prepared'); // prepared | dispatched | delivered | cancelled
            $t->timestamp('dispatched_at')->nullable();
            $t->string('dispatched_by_name', 120)->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->string('received_by_name', 120)->nullable();
            $t->string('received_by_phone', 30)->nullable();
            $t->string('pod_path')->nullable(); // proof of delivery: signed waybill or photo
            $t->string('pod_note', 500)->nullable();
            $t->string('pod_via', 12)->nullable(); // office | link (the receiver confirmed with the QR code)
            $t->string('confirm_token', 64)->nullable()->unique(); // the QR code's link for the receiver
            $t->string('notes', 500)->nullable();
            $t->string('cancel_reason')->nullable();
            $t->string('created_by_name', 120);
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waybills');
        Schema::table('sales', fn (Blueprint $t) => $t->dropColumn(['comment', 'comment_on_receipt', 'walk_in_name', 'walk_in_phone', 'extras']));
    }
};
