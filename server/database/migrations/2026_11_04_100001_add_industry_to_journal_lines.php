<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Industries part C (owner decision 2026-10-13): one set of books, each industry a profit centre. Every journal line
 * carries the industry of its branch at the time it was posted (null = company-level: head office and shared costs),
 * so a branch later moved to another industry does not rewrite history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $t) {
            $t->foreignUuid('industry_id')->nullable()->constrained()->restrictOnDelete();
            $t->index(['tenant_id', 'industry_id', 'entry_date']);
        });

        // One-time tagging of existing lines with a new dimension; amounts, accounts and dates are untouched, so the
        // append-only guard is lifted for this statement only.
        DB::unprepared(<<<'SQL'
            ALTER TABLE journal_lines DISABLE TRIGGER journal_lines_append_only;
            UPDATE journal_lines l SET industry_id = b.industry_id FROM branches b WHERE b.id = l.branch_id AND b.industry_id IS NOT NULL;
            ALTER TABLE journal_lines ENABLE TRIGGER journal_lines_append_only;
        SQL);
    }

    public function down(): void
    {
        Schema::table('journal_lines', fn (Blueprint $t) => $t->dropConstrainedForeignId('industry_id'));
    }
};
