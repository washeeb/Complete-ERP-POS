<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// How strictly prescription-only and controlled medicines are sold is a setting (company default, a branch may differ):
// branches keep their own values for such settings, and a pharmacist can sign off afterwards the medicine sales made
// without a pharmacist's approval.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', fn (Blueprint $t) => $t->jsonb('settings')->nullable()); // key => value; missing = the company setting
        Schema::create('pharmacy_signoffs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->index()->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->index()->constrained()->restrictOnDelete();
            $t->foreignUuid('sale_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignUuid('signed_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $t->string('signed_by_name', 120);
            $t->string('note', 255)->nullable();
            $t->timestamp('signed_at');
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_signoffs');
        Schema::table('branches', fn (Blueprint $t) => $t->dropColumn('settings'));
    }
};
