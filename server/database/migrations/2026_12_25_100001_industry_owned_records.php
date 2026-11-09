<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Every industry independent (owner 2026-11-09: the supermarket's categories and users showed in the pharmacy and the
 * other way round). Customers, suppliers, categories, brands, promotions, styles, choice groups and campaigns now
 * belong to an industry; null = shared, chosen on purpose.
 *
 * Existing records are given the industry they are used in (owner decisions 2026-11-09):
 *  - categories and brands: by their products; one used in several industries is copied, one per industry, and each
 *    product moves to its own industry's copy (a brand name is unique within an industry from now on);
 *  - customers: where they bought (receipts and invoices, most first), else their home branch, else the first industry;
 *  - suppliers: by the products they supply and the orders placed with them; several industries = shared; none = the
 *    first industry (the owner can move one by editing it);
 *  - promotions: by their products and categories (several = shared); styles: the fashion industry; choice groups: the
 *    restaurant industry; campaigns: shared.
 * A company without industries keeps everything shared.
 */
return new class extends Migration
{
    private const TABLES = ['categories', 'brands', 'customers', 'suppliers', 'promotions', 'styles', 'modifier_groups', 'crm_campaigns'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignUuid('industry_id')->nullable()->constrained()->restrictOnDelete(); // null = shared by all industries
                $t->index('industry_id');
            });
        }
        // A brand name is unique within its industry (the same brand may exist in each). Categories had no such rule.
        Schema::table('brands', fn (Blueprint $t) => $t->dropUnique(['tenant_id', 'name']));
        DB::statement("CREATE UNIQUE INDEX brands_name_per_industry ON brands (tenant_id, name, COALESCE(industry_id, '00000000-0000-0000-0000-000000000000'::uuid))");

        foreach (DB::table('tenants')->pluck('id') as $tenant) {
            $industries = DB::table('industries')->where('tenant_id', $tenant)->orderBy('sort')->orderBy('created_at')->pluck('id');
            if ($industries->isEmpty()) {
                continue;
            }
            $first = $industries->first();
            foreach (['category_id' => 'categories', 'brand_id' => 'brands'] as $column => $table) {
                $this->split($tenant, $table, $column);
            }
            $this->customers($tenant, $first);
            $this->suppliers($tenant, $first);
            $this->promotions($tenant);
            DB::table('styles')->where('tenant_id', $tenant)->update(['industry_id' => DB::table('industries')->where('tenant_id', $tenant)->where('module_key', 'fashion')->value('id')]);
            DB::table('modifier_groups')->where('tenant_id', $tenant)->update(['industry_id' => DB::table('industries')->where('tenant_id', $tenant)->where('module_key', 'restaurant')->value('id')]);
        }
    }

    /** A category or brand goes to the industry of its products; one used by several is copied, one per industry. */
    private function split(string $tenant, string $table, string $column): void
    {
        foreach (DB::table($table)->where('tenant_id', $tenant)->get() as $row) {
            $used = DB::table('products')->where($column, $row->id)->select('industry_id')->distinct()->pluck('industry_id');
            $industries = $used->filter()->values();
            if ($industries->isEmpty()) {
                continue; // unused, or only shared products: stays shared
            }
            DB::table($table)->where('id', $row->id)->update(['industry_id' => $industries->first()]);
            foreach ($industries->slice(1) as $industry) {
                $copy = (array) $row;
                $copy['id'] = (string) Str::uuid7();
                $copy['industry_id'] = $industry;
                if (array_key_exists('code', $copy) && $copy['code'] !== null) {
                    $copy['code'] = $this->freeCode($table, $tenant, (string) $copy['code']);
                }
                foreach (['created_at', 'updated_at'] as $ts) {
                    if (array_key_exists($ts, $copy)) {
                        $copy[$ts] = now();
                    }
                }
                if (array_key_exists('version', $copy)) {
                    $copy['version'] = 1;
                }
                DB::table($table)->insert($copy);
                DB::table('products')->where($column, $row->id)->where('industry_id', $industry)->update([$column => $copy['id']]);
            }
        }
    }

    private function freeCode(string $table, string $tenant, string $code): string
    {
        for ($n = 2; ; $n++) {
            $try = mb_substr($code, 0, 16).'-'.$n;
            if (! DB::table($table)->where('tenant_id', $tenant)->where('code', $try)->exists()) {
                return $try;
            }
        }
    }

    private function customers(string $tenant, string $first): void
    {
        $byBranch = fn (string $table) => DB::table($table.' as d')->join('branches as b', 'b.id', '=', 'd.branch_id')
            ->where('d.tenant_id', $tenant)->whereNotNull('d.customer_id')->whereNotNull('b.industry_id')
            ->selectRaw('d.customer_id, b.industry_id, count(*) as n')->groupBy('d.customer_id', 'b.industry_id')->get();
        $counts = [];
        foreach ([...$byBranch('sales'), ...$byBranch('invoices')] as $r) {
            $counts[$r->customer_id][$r->industry_id] = ($counts[$r->customer_id][$r->industry_id] ?? 0) + $r->n;
        }
        $home = DB::table('customers as c')->join('branches as b', 'b.id', '=', 'c.home_branch_id')->where('c.tenant_id', $tenant)->pluck('b.industry_id', 'c.id');
        foreach (DB::table('customers')->where('tenant_id', $tenant)->pluck('id') as $id) {
            $industry = isset($counts[$id]) ? array_search(max($counts[$id]), $counts[$id], true) : ($home[$id] ?? null);
            DB::table('customers')->where('id', $id)->update(['industry_id' => $industry ?: $first]);
        }
    }

    private function suppliers(string $tenant, string $first): void
    {
        foreach (DB::table('suppliers')->where('tenant_id', $tenant)->pluck('id') as $id) {
            $used = DB::table('supplier_products as s')->join('products as p', 'p.id', '=', 's.product_id')->where('s.supplier_id', $id)->pluck('p.industry_id')
                ->merge(DB::table('purchase_orders as o')->join('branches as b', 'b.id', '=', 'o.branch_id')->where('o.supplier_id', $id)->pluck('b.industry_id'))
                ->filter()->unique()->values();
            DB::table('suppliers')->where('id', $id)->update(['industry_id' => match ($used->count()) {
                0 => $first,
                1 => $used->first(),
                default => null, // serves several industries: shared
            }]);
        }
    }

    private function promotions(string $tenant): void
    {
        foreach (DB::table('promotions')->where('tenant_id', $tenant)->get(['id', 'product_ids', 'category_ids']) as $p) {
            $products = json_decode((string) $p->product_ids, true) ?: [];
            $categories = json_decode((string) $p->category_ids, true) ?: [];
            $used = DB::table('products')->whereIn('id', $products)->pluck('industry_id')
                ->merge(DB::table('categories')->whereIn('id', $categories)->pluck('industry_id'))->filter()->unique();
            DB::table('promotions')->where('id', $p->id)->update(['industry_id' => $used->count() === 1 ? $used->first() : null]);
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS brands_name_per_industry');
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('industry_id'));
        }
        // Copied categories and brands stay; brand names may now repeat across industries, so the old rule is not put back.
    }
};
