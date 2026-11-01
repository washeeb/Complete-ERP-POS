<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Withholding tax on supplier payments (procurement stage 2b). The company pays the supplier less the tax, owes that tax
 * to GRA, and gives the supplier a certificate. A payment's amount_minor stays what it settles on the supplier's account;
 * the cash paid out is amount_minor − wht_minor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payments', function (Blueprint $t) {
            $t->bigInteger('wht_minor')->default(0);
            $t->unsignedInteger('wht_rate_bp')->nullable();
            $t->bigInteger('wht_base_minor')->nullable(); // the part of the payment the tax is on (VAT left out)
            $t->string('wht_certificate_no', 40)->nullable();
            $t->index(['tenant_id', 'wht_certificate_no']);
        });
        // One-time: the chart's "Withholding tax payable" account becomes the one the books post to.
        foreach (DB::table('tenants')->pluck('id') as $tenant) {
            if (! DB::table('accounts')->where('tenant_id', $tenant)->where('system_key', 'wht_payable')->exists()) {
                DB::table('accounts')->where('tenant_id', $tenant)->where('code', '2240')->where('type', 'liability')->whereNull('system_key')
                    ->update(['system_key' => 'wht_payable']);
            }
        }
    }

    public function down(): void
    {
        DB::table('accounts')->where('system_key', 'wht_payable')->update(['system_key' => null]);
        Schema::table('supplier_payments', function (Blueprint $t) {
            $t->dropIndex(['tenant_id', 'wht_certificate_no']);
            $t->dropColumn(['wht_minor', 'wht_rate_bp', 'wht_base_minor', 'wht_certificate_no']);
        });
    }
};
