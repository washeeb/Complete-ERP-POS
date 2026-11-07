<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A barcode is unique within an industry, not across the company (owner 2026-11-07: the supermarket sells Lactogen
 * and olive oil that the pharmacy also has, each shop with its own product, name and price). A shared product's
 * barcode still stands alone: the service refuses it anywhere else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barcodes', function (Blueprint $t) {
            $t->foreignUuid('industry_id')->nullable()->after('product_uom_id')->constrained()->nullOnDelete(); // the product's; null = shared
            $t->index('industry_id');
        });
        DB::statement('UPDATE barcodes SET industry_id = p.industry_id FROM products p WHERE p.id = barcodes.product_id');
        Schema::table('barcodes', fn (Blueprint $t) => $t->dropUnique(['tenant_id', 'code']));
        DB::statement("CREATE UNIQUE INDEX barcodes_code_per_industry ON barcodes (tenant_id, code, COALESCE(industry_id, '00000000-0000-0000-0000-000000000000'::uuid))");
        DB::statement('CREATE INDEX barcodes_tenant_code ON barcodes (tenant_id, code)'); // scanning looks up by code
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS barcodes_tenant_code');
        DB::statement('DROP INDEX IF EXISTS barcodes_code_per_industry');
        Schema::table('barcodes', function (Blueprint $t) {
            $t->unique(['tenant_id', 'code']);
            $t->dropConstrainedForeignId('industry_id');
        });
    }
};
