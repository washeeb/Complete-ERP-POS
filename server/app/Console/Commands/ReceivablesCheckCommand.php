<?php

namespace App\Console\Commands;

use App\Domain\Customers\CustomerLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Proves customer balances equal their ledger entries, and no document is settled for more than it is worth. */
class ReceivablesCheckCommand extends Command
{
    protected $signature = 'erp:receivables-check';

    protected $description = 'Prove every customer balance equals its ledger, and allocations never exceed the documents';

    public function handle(CustomerLedger $ledger): int
    {
        $balances = $ledger->check();
        $over = DB::select(<<<'SQL'
            SELECT l.doc_number, l.amount_minor, SUM(a.amount_minor) AS settled
            FROM customer_ledger l JOIN receivable_allocations a ON a.to_type = l.doc_type AND a.to_id = l.doc_id
            WHERE l.type IN ('invoice', 'pos_sale', 'opening')
            GROUP BY l.doc_number, l.amount_minor HAVING SUM(a.amount_minor) > l.amount_minor
        SQL);
        if ($balances === [] && $over === []) {
            $this->info('Customer balances match the ledger; no document is over-settled.');

            return self::SUCCESS;
        }
        if ($balances) {
            $this->table(['customer', 'balance', 'ledger'], array_map(fn ($r) => (array) $r, $balances));
        }
        if ($over) {
            $this->table(['document', 'amount', 'settled'], array_map(fn ($r) => (array) $r, $over));
        }

        return self::FAILURE;
    }
}
