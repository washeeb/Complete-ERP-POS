<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Receipt numbers must never collide, even when a till is reinstalled (new device, old queue
        // still unsent) or a backup tablet sells on the same till. Each device gets its own series
        // letter per till: HQ-T01A-000123, HQ-T01B-000001.
        Schema::table('devices', fn (Blueprint $t) => $t->string('receipt_series', 4)->nullable());
        $byTill = DB::table('devices')->whereNotNull('till_id')->orderBy('created_at')->get()->groupBy('till_id');
        foreach ($byTill as $devices) {
            foreach ($devices->values() as $i => $d) {
                DB::table('devices')->where('id', $d->id)->update(['receipt_series' => self::series($i)]);
            }
        }
        DB::statement('CREATE UNIQUE INDEX devices_till_series_unique ON devices (till_id, receipt_series) WHERE till_id IS NOT NULL');

        // A shift belongs to the device it was opened on (offline tills open shifts without the server).
        DB::statement('DROP INDEX IF EXISTS shifts_one_open_per_till');
        DB::statement("CREATE UNIQUE INDEX shifts_one_open_per_device ON shifts (device_id) WHERE status = 'open'");

        Schema::table('sales', function (Blueprint $t) {
            $t->boolean('offline')->default(false); // made while the till could not reach this server
            $t->timestamp('grant_expires_at')->nullable(); // offline grant it was made under
            $t->string('catalog_version', 64)->nullable();
        });

        // Things a person must look at: offline sales at an old price, expired/revoked grants,
        // clock problems, negative stock after a merge, sync conflicts. Never auto-resolved.
        Schema::create('review_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('type', 40);
            $t->string('subject_type', 40);
            $t->uuid('subject_id')->nullable();
            $t->string('summary');
            $t->jsonb('details')->default('{}');
            $t->string('status', 12)->default('open'); // open | resolved
            $t->uuid('resolved_by')->nullable();
            $t->string('resolved_by_name')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->string('resolution')->nullable();
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['tenant_id', 'status']);
        });
    }

    public static function series(int $i): string
    {
        $s = '';
        for ($n = $i; $n >= 0; $n = intdiv($n, 26) - 1) {
            $s = chr(65 + $n % 26).$s;
        }

        return $s;
    }

    public function down(): void
    {
        Schema::dropIfExists('review_items');
        Schema::table('sales', fn (Blueprint $t) => $t->dropColumn(['offline', 'grant_expires_at', 'catalog_version']));
        DB::statement('DROP INDEX IF EXISTS shifts_one_open_per_device');
        DB::statement("CREATE UNIQUE INDEX shifts_one_open_per_till ON shifts (till_id) WHERE status = 'open'");
        DB::statement('DROP INDEX IF EXISTS devices_till_series_unique');
        Schema::table('devices', fn (Blueprint $t) => $t->dropColumn('receipt_series'));
    }
};
