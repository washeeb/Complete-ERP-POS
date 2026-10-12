<?php

use App\Modules\ModuleRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Industries (owner decisions 2026-10-12): a level between the company and its branches. Each branch belongs to one
 * industry (Pharmacy, Grocery, …); an industry has any number of branches; head office or a central warehouse may
 * belong to none ("company-level"). Products and price lists belong to one industry (fully separate catalogues);
 * existing ones stay company-wide (null) until moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('industries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('code', 10);
            $t->string('name');
            $t->string('module_key', 40); // the industry module its branches run (pharmacy, grocery, …)
            $t->foreignUuid('legal_entity_id')->nullable()->constrained()->nullOnDelete(); // separate books (Phase C)
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });
        Schema::table('branches', function (Blueprint $t) {
            $t->foreignUuid('industry_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('products', function (Blueprint $t) {
            $t->foreignUuid('industry_id')->nullable()->constrained()->nullOnDelete();
            $t->index(['tenant_id', 'industry_id']);
        });
        Schema::table('price_lists', function (Blueprint $t) {
            $t->foreignUuid('industry_id')->nullable()->constrained()->nullOnDelete();
        });

        // Backfill: a branch that runs an industry module becomes a branch of that industry.
        $registry = app(ModuleRegistry::class);
        $rows = DB::table('branch_modules as bm')->join('branches as b', 'b.id', '=', 'bm.branch_id')
            ->where('bm.status', 'enabled')->orderBy('b.code')->get(['b.id as branch_id', 'b.tenant_id', 'bm.module_key']);
        $made = [];
        foreach ($rows->groupBy('branch_id') as $branchId => $mods) {
            $industryModule = $mods->first(fn ($m) => $registry->has($m->module_key) && $registry->get($m->module_key)->definesIndustry());
            if (! $industryModule) {
                continue;
            }
            $tenant = $industryModule->tenant_id;
            $key = $industryModule->module_key;
            if (! isset($made[$tenant][$key])) {
                $id = DB::table('industries')->where('tenant_id', $tenant)->where('module_key', $key)->value('id');
                if (! $id) {
                    $id = (string) Str::uuid7();
                    DB::table('industries')->insert(['id' => $id, 'tenant_id' => $tenant, 'code' => strtoupper(substr(preg_replace('/[^a-z]/', '', $key), 0, 6)),
                        'name' => $registry->get($key)->name, 'module_key' => $key, 'sort' => count($made[$tenant] ?? []) + 1, 'is_active' => true,
                        'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                }
                $made[$tenant][$key] = $id;
            }
            DB::table('branches')->where('id', $branchId)->update(['industry_id' => $made[$tenant][$key], 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (['price_lists', 'products', 'branches'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('industry_id');
            });
        }
        Schema::dropIfExists('industries');
    }
};
