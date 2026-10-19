<?php

use App\Domain\Inventory\ZeroStock;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;

// Products that never moved had no stock row, so they were missing from Stock on hand. Every stock-tracked product
// now has one (at 0) in each store of its industry.
return new class extends Migration
{
    public function up(): void
    {
        foreach (Tenant::all() as $tenant) {
            ZeroStock::ensure($tenant->id);
        }
    }

    public function down(): void
    {
        // Rows at 0 with no movements are harmless; they stay.
    }
};
