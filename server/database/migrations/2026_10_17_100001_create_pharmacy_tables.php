<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9b: Pharmacy — drug details on products, patient profiles, prescriptions, interaction table, and the
 * pharmacist's approval on sales. The controlled-drug register is read from the stock ledger (already append-only)
 * joined to these, so it cannot drift from the stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->string('drug_schedule', 12)->nullable(); // otc, pom (prescription only), controlled
            $t->string('generic_name')->nullable();
            $t->string('strength', 60)->nullable();
            $t->string('dosage_form', 40)->nullable();
            $t->string('fda_reg_no', 60)->nullable(); // Ghana FDA registration number
            $t->jsonb('active_ingredients')->nullable(); // lower-case names, for allergy and interaction warnings
        });

        Schema::create('patient_profiles', function (Blueprint $t) {
            $t->foreignUuid('customer_id')->primary()->constrained()->restrictOnDelete();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->date('date_of_birth')->nullable();
            $t->string('sex', 10)->nullable();
            $t->jsonb('allergies')->nullable(); // ingredient names (lower case)
            $t->text('conditions')->nullable();
            $t->string('nhis_no', 30)->nullable();
            $t->date('nhis_expires_on')->nullable();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        Schema::create('drug_interactions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('ingredient_a', 120);
            $t->string('ingredient_b', 120);
            $t->string('severity', 10); // minor, moderate, major
            $t->string('note');
            $t->string('source', 60)->default('local'); // where the rule came from (local table now; a data provider later)
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'ingredient_a', 'ingredient_b']);
        });

        Schema::create('prescriptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('customer_id')->constrained()->restrictOnDelete(); // the patient
            $t->foreignUuid('sale_id')->nullable()->constrained()->nullOnDelete();
            $t->string('prescriber_name');
            $t->string('prescriber_reg_no', 60)->nullable(); // Medical & Dental Council registration
            $t->string('facility')->nullable();
            $t->string('reference', 60)->nullable(); // number on the paper prescription
            $t->date('issued_on');
            $t->string('pharmacist_name');
            $t->uuid('pharmacist_id')->nullable();
            $t->boolean('approved_offline')->default(false);
            $t->jsonb('warnings')->nullable(); // allergy / interaction warnings the pharmacist saw
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['customer_id', 'issued_on']);
        });

        Schema::table('sale_lines', function (Blueprint $t) {
            $t->uuid('prescription_id')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('sale_lines', 'prescription_id')) {
            Schema::table('sale_lines', fn (Blueprint $t) => $t->dropColumn('prescription_id'));
        }
        foreach (['prescriptions', 'drug_interactions', 'patient_profiles'] as $t) {
            Schema::dropIfExists($t);
        }
        if (Schema::hasColumn('products', 'drug_schedule')) {
            Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['drug_schedule', 'generic_name', 'strength', 'dosage_form', 'fda_reg_no', 'active_ingredients']));
        }
    }
};
