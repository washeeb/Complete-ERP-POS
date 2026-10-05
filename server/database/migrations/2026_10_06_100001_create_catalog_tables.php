<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uoms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('code', 10); // PC, PK, CTN, KG, L
            $table->string('name', 60);
            $table->unsignedSmallInteger('decimals')->default(0); // quantity precision when selling in this unit (0–3)
            $table->string('origin_node', 40);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->uuid('parent_id')->nullable(); // FK added below, once the primary key exists
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->string('origin_node', 40);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::table('categories', fn (Blueprint $table) => $table->foreign('parent_id')->references('id')->on('categories')->restrictOnDelete());

        Schema::create('brands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('origin_node', 40);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        // Tax is configuration, not code: components (rates) grouped into what a product carries.
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);     // VAT, NHIL, GETFUND
            $table->string('name', 80);
            $table->unsignedInteger('rate_bp'); // basis points: 1500 = 15.00%
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('origin_node', 40);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('tax_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 80);
            // Seeded tax set-ups are unverified until an accountant signs them off (spec §7).
            $table->timestamp('verified_at')->nullable();
            $table->uuid('verified_by')->nullable();
            $table->string('verified_by_name')->nullable();
            $table->string('origin_node', 40);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('tax_group_rates', function (Blueprint $table) {
            $table->foreignUuid('tax_group_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('tax_rate_id')->constrained()->restrictOnDelete();
            $table->primary(['tax_group_id', 'tax_rate_id']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('sku', 40);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->foreignUuid('category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('brand_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('base_uom_id')->constrained('uoms')->restrictOnDelete();
            $table->foreignUuid('tax_group_id')->constrained()->restrictOnDelete();
            $table->boolean('track_stock')->default(true);
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->string('origin_node', 40);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'sku']);
        });
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX products_name_trgm ON products USING gin (name gin_trgm_ops)');

        // Units a product is bought/sold in. factor = how many base units in one of these (carton = 24 pieces).
        Schema::create('product_uoms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('factor', 18, 6);
            $table->boolean('is_base')->default(false);
            $table->boolean('is_sellable')->default(true);
            $table->boolean('is_purchasable')->default(true);
            $table->string('origin_node', 40);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['product_id', 'uom_id']);
        });

        Schema::create('barcodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_uom_id')->constrained()->cascadeOnDelete(); // scanning it adds this unit
            $table->string('code', 64);
            $table->string('origin_node', 40);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('price_lists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 80);
            $table->char('currency_code', 3);
            $table->boolean('prices_include_tax')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('pos_selectable')->default(true); // cashier may switch to it at the till
            $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete(); // null = all branches
            $table->boolean('is_active')->default(true);
            $table->string('origin_node', 40);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        // Price per list + product unit, optionally from a minimum quantity (tiers) and within dates (scheduled).
        Schema::create('prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_uom_id')->constrained()->cascadeOnDelete();
            $table->decimal('min_qty', 18, 3)->default(1);
            $table->bigInteger('amount_minor');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->string('origin_node', 40);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['price_list_id', 'product_id']);
        });
    }

    public function down(): void
    {
        foreach (['prices', 'price_lists', 'barcodes', 'product_uoms', 'products', 'tax_group_rates', 'tax_groups', 'tax_rates', 'brands', 'categories', 'uoms'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
