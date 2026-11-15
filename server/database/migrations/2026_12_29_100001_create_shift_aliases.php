<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shift the till opened twice (owner report 2026-11-15, SUPBOG: Open shift pressed again while the first press was
 * still on its way; the server accepted one, the till kept selling under the other, and every sale in it was refused).
 * The second shift's id is kept here as another name for the first, so its sales, drawer events and close land in it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_aliases', function (Blueprint $t) {
            $t->uuid('id')->primary(); // the id the till gave its second shift
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('shift_id')->constrained()->restrictOnDelete(); // the shift the server already had
            $t->foreignUuid('device_id')->constrained()->restrictOnDelete();
            $t->timestamp('opened_at')->nullable(); // when the till opened the second one
            $t->timestamps();
            $t->index('tenant_id');
            $t->index('shift_id');
            $t->index('device_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_aliases');
    }
};
