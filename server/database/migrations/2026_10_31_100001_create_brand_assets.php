<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client branding (2026-10-31): each company's logo (light and dark backgrounds), square icon and brand colour, used on
 * the back office, tills, receipts, invoices, PDFs and the online shop. Images are kept in the database (small,
 * re-encoded on upload) so they sync to branch servers and are in every backup, like the rest of the company's data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_assets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('kind', 12); // logo, logo_dark, icon
            $t->string('mime', 40); // image/png, image/jpeg, image/webp, image/svg+xml
            $t->text('data'); // base64
            $t->unsignedInteger('width')->default(0);
            $t->unsignedInteger('height')->default(0);
            $t->unsignedInteger('bytes')->default(0);
            $t->string('sha256', 64);
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'kind']);
        });

        Schema::table('tenants', function (Blueprint $t) {
            $t->string('brand_color', 7)->nullable(); // #RRGGBB; null = the product colour
            $t->boolean('brand_receipt_logo')->default(true); // print the logo on till receipts
        });
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn(['brand_color', 'brand_receipt_logo']));
        Schema::dropIfExists('brand_assets');
    }
};
