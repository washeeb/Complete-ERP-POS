<?php

use App\Domain\Catalog\IndustryUnits;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Companies already running Pharmacy get its units (tablet, strip, ampoule, vial, …); new ones get them when the
// module is switched on. Only missing units are added.
return new class extends Migration
{
    public function up(): void
    {
        $tenants = DB::table('branch_modules')->where('module_key', 'pharmacy')->where('status', 'enabled')->distinct()->pluck('tenant_id');
        foreach ($tenants as $tenantId) {
            app(IndustryUnits::class)->ensure($tenantId, 'pharmacy');
        }
    }

    public function down(): void {}
};
