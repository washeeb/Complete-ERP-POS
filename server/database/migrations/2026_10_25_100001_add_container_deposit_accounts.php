<?php

use App\Domain\Finance\ChartTemplate;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;

/**
 * Container deposits can be booked as sales (default) or as a liability (owner decision 2026-10-25, "keep both options").
 * Existing charts get the two new system accounts here; new companies get them from the template.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Tenant::all() as $tenant) {
            ChartTemplate::ensure($tenant);
        }
    }

    public function down(): void {}
};
