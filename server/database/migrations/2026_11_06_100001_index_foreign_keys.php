<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL does not index foreign keys by itself. Every query is filtered by company (tenant_id), most by branch,
 * product or parent document, so each foreign-key column that does not start an index gets one. Without them, lists,
 * reports and the loading of a document's lines slow down as the data grows. The test
 * DatabaseIndexesTest keeps new tables from missing one.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (self::unindexed() as $r) {
            $name = substr("{$r->tbl}_{$r->col}", 0, 55).'_fk_idx';
            DB::statement("CREATE INDEX IF NOT EXISTS \"{$name}\" ON \"{$r->tbl}\" (\"{$r->col}\")");
        }
        // The busiest lists, newest first for the company, and searching customers by any part of the name.
        DB::statement('CREATE INDEX IF NOT EXISTS sales_tenant_occurred_fk_idx ON sales (tenant_id, occurred_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS stock_movements_tenant_occurred_fk_idx ON stock_movements (tenant_id, occurred_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS invoices_tenant_issued_fk_idx ON invoices (tenant_id, issued_on DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS customers_name_trgm_fk_idx ON customers USING gin (name gin_trgm_ops)');
    }

    public function down(): void
    {
        foreach (DB::select("SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND indexname LIKE '%\\_fk\\_idx'") as $i) {
            DB::statement("DROP INDEX IF EXISTS \"{$i->indexname}\"");
        }
    }

    /** Single-column foreign keys with no index that starts with that column. */
    public static function unindexed(): array
    {
        return DB::select(<<<'SQL'
            SELECT c.conrelid::regclass::text AS tbl, a.attname AS col
            FROM pg_constraint c
            JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
            JOIN pg_class t ON t.oid = c.conrelid
            JOIN pg_namespace n ON n.oid = t.relnamespace AND n.nspname = current_schema()
            WHERE c.contype = 'f' AND array_length(c.conkey, 1) = 1
              AND NOT EXISTS (SELECT 1 FROM pg_index i WHERE i.indrelid = c.conrelid AND i.indkey[0] = c.conkey[1])
            ORDER BY 1, 2
        SQL);
    }
};
