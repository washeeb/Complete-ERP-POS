<?php

namespace App\Console\Commands;

use App\Domain\Customers\CustomerLedger;
use App\Domain\Customers\Loyalty;
use App\Domain\Customers\StoredValues;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Proves customer balances equal their ledger entries, and no document is settled for more than it is worth. */
class ReceivablesCheckCommand extends Command
{
    protected $signature = 'erp:receivables-check';

    protected $description = 'Prove customer balances, loyalty points and gift card / store credit balances equal their ledgers, and allocations never exceed the documents';

    public function handle(CustomerLedger $ledger, Loyalty $loyalty, StoredValues $stored): int
    {
        $balances = array_merge($ledger->check(), $loyalty->check(), $stored->check());
        $over = DB::select(<<<'SQL'
            SELECT l.doc_number, l.amount_minor, SUM(a.amount_minor) AS settled
            FROM customer_ledger l JOIN receivable_allocations a ON a.to_type = l.doc_type AND a.to_id = l.doc_id
            WHERE l.type IN ('invoice', 'pos_sale', 'opening')
            GROUP BY l.doc_number, l.amount_minor HAVING SUM(a.amount_minor) > l.amount_minor
        SQL);
        if ($balances === [] && $over === []) {
            $this->info('Customer balances, points and card balances match their ledgers; no document is over-settled.');

            return self::SUCCESS;
        }
        if ($balances) {
            $this->table(['account', 'balance', 'ledger'], array_map(fn ($r) => array_values((array) $r), $balances));
        }
        if ($over) {
            $this->table(['document', 'amount', 'settled'], array_map(fn ($r) => (array) $r, $over));
        }

        return self::FAILURE;
    }
}
