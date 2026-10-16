<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A closed shift's count is never edited: a manager records a correction (the right amount and why), which posts the
// difference to the books. The shift keeps its original count; screens show the corrected one beside it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_count_corrections', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->index()->constrained()->restrictOnDelete();
            $t->foreignUuid('shift_id')->index()->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->index()->constrained()->restrictOnDelete();
            $t->bigInteger('counted_before_minor');
            $t->bigInteger('counted_after_minor');
            $t->bigInteger('over_short_after_minor');
            $t->string('reason', 255);
            $t->foreignUuid('corrected_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $t->string('corrected_by_name', 120);
            $t->timestamp('corrected_at');
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_count_corrections');
    }
};
