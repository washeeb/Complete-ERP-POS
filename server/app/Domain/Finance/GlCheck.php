<?php

namespace App\Domain\Finance;

use Illuminate\Support\Facades\DB;

/**
 * Proves the general ledger: every entry balances, nothing sits on a heading account, and each control account
 * equals its sub-ledger — customers, suppliers, stock at cost, gift cards and store credit, loyalty points
 * (Phase 7 done-when: the trial balance ties to the sub-ledgers). Run after automatic posting has caught up.
 */
final class GlCheck
{
    /** Control account → [label, sub-ledger total (debit +)]. Liabilities are owed, so their sub-ledger is negated. */
    public static function subLedgers(string $tenantId): array
    {
        $one = fn (string $sql) => (int) DB::selectOne($sql, [$tenantId])->v;

        return [
            'receivables' => ['Customer accounts', $one('SELECT COALESCE(SUM(balance_minor), 0) AS v FROM customer_balances WHERE tenant_id = ?')],
            'payables' => ['Supplier accounts', -$one('SELECT COALESCE(SUM(balance_minor), 0) AS v FROM supplier_balances WHERE tenant_id = ?')],
            'inventory' => ['Stock at cost', $one("SELECT COALESCE(SUM(ROUND(qty_entered * COALESCE(unit_cost_minor, 0))), 0)::bigint AS v FROM stock_movements WHERE tenant_id = ? AND type NOT IN ('putaway', 'batch_assign')")],
            'stored_value' => ['Gift cards and store credit', -$one('SELECT COALESCE(SUM(balance_minor), 0) AS v FROM stored_value_balances WHERE tenant_id = ?')],
            'loyalty' => ['Loyalty points (1 point = 1 pesewa)', -$one('SELECT COALESCE(SUM(points), 0) AS v FROM loyalty_balances WHERE tenant_id = ?')],
        ];
    }

    /**
     * @return array{ok: bool, ties: list<array{control: string, label: string, ledger: int, sub_ledger: int, difference: int}>, problems: list<string>, opening_left: int}
     */
    public function run(string $tenantId): array
    {
        $problems = [];
        foreach (DB::select('SELECT e.number, SUM(l.amount_minor) AS s FROM journal_entries e JOIN journal_lines l ON l.journal_entry_id = e.id
            WHERE e.tenant_id = ? GROUP BY e.number HAVING SUM(l.amount_minor) <> 0', [$tenantId]) as $r) {
            $problems[] = "{$r->number} does not balance ({$r->s}).";
        }
        // Separate books: each entry balances inside every legal entity it touches, and what one company shows as owed by
        // another, the other shows as owed to it.
        foreach (DB::select('SELECT e.number, le.name, SUM(l.amount_minor) AS s FROM journal_entries e JOIN journal_lines l ON l.journal_entry_id = e.id
            JOIN legal_entities le ON le.id = l.legal_entity_id WHERE e.tenant_id = ? GROUP BY e.number, le.name HAVING SUM(l.amount_minor) <> 0', [$tenantId]) as $r) {
            $problems[] = "{$r->number} does not balance in the books of {$r->name} ({$r->s}).";
        }
        foreach (DB::select("SELECT a.name AS a_name, b.name AS b_name, SUM(l.amount_minor) AS s FROM journal_lines l
            JOIN accounts acc ON acc.id = l.account_id AND acc.system_key = 'intercompany'
            JOIN legal_entities a ON a.id = LEAST(l.legal_entity_id, l.party_id) JOIN legal_entities b ON b.id = GREATEST(l.legal_entity_id, l.party_id)
            WHERE l.tenant_id = ? AND l.party_type = 'legal_entity' GROUP BY a.name, b.name HAVING SUM(l.amount_minor) <> 0", [$tenantId]) as $r) {
            $problems[] = "What {$r->a_name} and {$r->b_name} owe each other does not agree in their books ({$r->s}).";
        }
        foreach (DB::select('SELECT a.code, a.name, COUNT(*) AS n FROM journal_lines l JOIN accounts a ON a.id = l.account_id
            WHERE l.tenant_id = ? AND a.is_header GROUP BY a.code, a.name', [$tenantId]) as $r) {
            $problems[] = "{$r->n} line(s) posted to the heading {$r->code} {$r->name}.";
        }
        $gl = collect(DB::select('SELECT a.control, COALESCE(SUM(l.amount_minor), 0) AS s FROM accounts a LEFT JOIN journal_lines l ON l.account_id = a.id
            WHERE a.tenant_id = ? AND a.control IS NOT NULL GROUP BY a.control', [$tenantId]))->pluck('s', 'control');
        $ties = [];
        foreach (self::subLedgers($tenantId) as $control => [$label, $sub]) {
            $ledger = (int) ($gl[$control] ?? 0);
            $ties[] = ['control' => $control, 'label' => $label, 'ledger' => $ledger, 'sub_ledger' => $sub, 'difference' => $ledger - $sub];
            if ($ledger !== $sub) {
                $problems[] = "{$label}: the ledger shows ".number_format($ledger / 100, 2).' but the sub-ledger '.number_format($sub / 100, 2).'.';
            }
        }
        $opening = (int) DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_lines.tenant_id', $tenantId)->where('accounts.system_key', 'opening_equity')->sum('journal_lines.amount_minor');

        return ['ok' => $problems === [], 'ties' => $ties, 'problems' => $problems, 'opening_left' => $opening];
    }
}
