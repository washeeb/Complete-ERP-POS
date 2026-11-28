<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shift continued on a replacement till (the first one was wiped, reinstalled or replaced while the shift was open)
 * remembers the device it was opened on, so sales that device still sends land in the same shift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $t) {
            $t->foreignUuid('taken_over_from_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $t->index('taken_over_from_device_id');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('taken_over_from_device_id');
        });
    }
};
