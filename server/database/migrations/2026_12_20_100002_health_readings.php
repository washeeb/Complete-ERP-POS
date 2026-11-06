<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fee services with a reading (owner request 2026-11-06): blood pressure, sugar, weight, temperature. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->string('reading_kind', 20)->nullable()); // sold as a service, with this reading taken

        Schema::create('health_readings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('sale_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();
            $t->string('kind', 20); // bp, sugar, weight, temperature
            $t->unsignedSmallInteger('systolic')->nullable();
            $t->unsignedSmallInteger('diastolic')->nullable();
            $t->unsignedSmallInteger('pulse')->nullable();
            $t->decimal('value', 8, 2)->nullable();
            $t->string('flag', 12); // normal, low, high, urgent
            $t->string('note')->nullable();
            $t->string('taken_by_name');
            $t->foreignUuid('taken_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('taken_at');
            $t->string('origin_node', 40)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['tenant_id', 'taken_at']);
            $t->index(['customer_id', 'taken_at']);
            $t->index('branch_id');
            $t->index('sale_id');
            $t->index('product_id');
            $t->index('taken_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_readings');
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('reading_kind'));
    }
};
