<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time: names already in the system in capitals, like everything saved from now on (owner decision 2026-10-22):
 * products (name, generic name, strength, form), categories, brands, units, suppliers and customers (name, contact).
 * Only rows that change are touched; their updated_at moves so tills fetch the new catalogue. Addresses, notes,
 * e-mails and passwords are not touched.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'products' => ['name', 'generic_name', 'strength', 'dosage_form'],
        'categories' => ['name'],
        'brands' => ['name'],
        'uoms' => ['name'],
        'suppliers' => ['name', 'contact_name'],
        'customers' => ['name', 'contact_name'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            $set = implode(', ', array_map(fn ($c) => "{$c} = UPPER({$c})", $columns));
            $changed = implode(' OR ', array_map(fn ($c) => "{$c} IS DISTINCT FROM UPPER({$c})", $columns));
            DB::statement("UPDATE {$table} SET {$set}, updated_at = NOW() WHERE {$changed}");
        }
    }

    public function down(): void
    {
        // The earlier spelling is not kept; nothing to undo.
    }
};
