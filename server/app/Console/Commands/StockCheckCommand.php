<?php

namespace App\Console\Commands;

use App\Domain\Inventory\StockLedger;
use Illuminate\Console\Command;

class StockCheckCommand extends Command
{
    protected $signature = 'erp:stock-check';

    protected $description = 'Prove every stock level equals the sum of its ledger movements';

    public function handle(StockLedger $ledger): int
    {
        $bad = $ledger->check();
        if ($bad === []) {
            $this->info('Stock levels match the ledger.');

            return self::SUCCESS;
        }
        $this->error(count($bad).' stock level(s) differ from the ledger:');
        $this->table(['warehouse', 'product', 'on hand', 'ledger'], array_map(fn ($r) => (array) $r, $bad));

        return self::FAILURE;
    }
}
