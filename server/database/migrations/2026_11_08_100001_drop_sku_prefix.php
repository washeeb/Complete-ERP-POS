<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Support\Actor;
use App\Domain\Support\Recorder;
use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// SKUs no longer start with "SKU": shared products get plain numbers (SKU-000123 → 100123), products of an industry its
// series (PHARMA-000124). Each change is audited and synced. SKUs typed in from an old system are not touched.
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('document_sequences')->where('node', 'catalog')->where('doc_type', 'SKU')->get() as $seq) {
            DB::table('document_sequences')->insertOrIgnore(['tenant_id' => $seq->tenant_id, 'node' => 'catalog', 'doc_type' => 'SHARED', 'last_value' => $seq->last_value]);
            DB::table('document_sequences')->where('tenant_id', $seq->tenant_id)->where('node', 'catalog')->where('doc_type', 'SKU')->delete();
        }
        $catalog = app(CatalogService::class);
        $recorder = app(Recorder::class);
        Product::withoutGlobalScopes()->where('sku', '~', '^SKU-[0-9]{6}$')->orderBy('sku')->each(function (Product $p) use ($catalog, $recorder) {
            DB::transaction(function () use ($p, $catalog, $recorder) {
                $plain = (string) (CatalogService::SHARED_SKU_FROM + (int) substr($p->sku, 4));
                $free = ! Product::withoutGlobalScopes()->where('tenant_id', $p->tenant_id)->where('sku', $plain)->exists();
                $p->sku = $p->industry_id === null && $free ? $plain : $catalog->nextSku($p->tenant_id, $p->industry_id);
                $recorder->save(Actor::system($p->tenant_id, 'SKU update'), $p, 'product.updated', 'SKUs no longer start with "SKU"');
            });
        });
    }

    public function down(): void {}
};
