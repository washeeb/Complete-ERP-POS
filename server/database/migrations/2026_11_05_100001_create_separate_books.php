<?php

use App\Domain\Finance\ChartTemplate;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separate books per legal entity (owner, 2026-10-13: "build it now"). An industry registered as its own company gets
 * its own legal entity; its branches post into that entity's books, and every entry that touches two entities is
 * balanced inside each one through "Due from / to group companies". One entity is the primary (head office); lines
 * without a branch belong to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_entities', function (Blueprint $t) {
            $t->boolean('is_primary')->default(false);
            $t->string('address')->nullable();
            $t->boolean('is_active')->default(true);
        });
        DB::statement(<<<'SQL'
            UPDATE legal_entities le SET is_primary = true
            WHERE le.id = (SELECT x.id FROM legal_entities x WHERE x.tenant_id = le.tenant_id ORDER BY x.created_at, x.id LIMIT 1)
        SQL);

        Schema::table('journal_lines', function (Blueprint $t) {
            $t->foreignUuid('legal_entity_id')->nullable()->constrained()->restrictOnDelete();
            $t->index(['tenant_id', 'legal_entity_id', 'entry_date']);
        });
        // One-time tagging of existing lines: the branch's legal entity, else the primary one. Amounts, accounts and
        // dates are untouched, so the append-only guard is lifted for these statements only.
        DB::unprepared(<<<'SQL'
            ALTER TABLE journal_lines DISABLE TRIGGER journal_lines_append_only;
            UPDATE journal_lines l SET legal_entity_id = b.legal_entity_id FROM branches b WHERE b.id = l.branch_id AND b.legal_entity_id IS NOT NULL;
            UPDATE journal_lines l SET legal_entity_id = le.id FROM legal_entities le
                WHERE l.legal_entity_id IS NULL AND le.tenant_id = l.tenant_id AND le.is_primary;
            ALTER TABLE journal_lines ENABLE TRIGGER journal_lines_append_only;
        SQL);

        foreach (Tenant::all() as $tenant) {
            ChartTemplate::ensure($tenant); // adds "Due from / to group companies"
        }
    }

    public function down(): void
    {
        Schema::table('journal_lines', fn (Blueprint $t) => $t->dropConstrainedForeignId('legal_entity_id'));
        Schema::table('legal_entities', fn (Blueprint $t) => $t->dropColumn(['is_primary', 'address', 'is_active']));
    }
};
