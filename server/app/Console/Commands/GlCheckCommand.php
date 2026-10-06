<?php

namespace App\Console\Commands;

use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\GlCheck;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/** Proves the trial balance ties to the sub-ledgers (posts anything outstanding first). */
class GlCheckCommand extends Command
{
    protected $signature = 'erp:gl-check {--no-post : check without posting outstanding records first}';

    protected $description = 'Prove every journal balances and the ledger equals customers, suppliers, stock, gift cards and points';

    public function handle(AutoPoster $poster, GlCheck $check): int
    {
        if (config('erp.node.role') === 'branch') {
            $this->info('The general ledger is kept at head office.');

            return self::SUCCESS;
        }
        $ok = true;
        foreach (Tenant::all() as $tenant) {
            app(TenantContext::class)->set($tenant->id);
            if (! $this->option('no-post')) {
                $poster->run($tenant->id);
            }
            $r = $check->run($tenant->id);
            $this->table(['Control account', 'Ledger', 'Sub-ledger', 'Difference'], array_map(fn ($t) => [$t['label'], number_format($t['ledger'] / 100, 2),
                number_format($t['sub_ledger'] / 100, 2), number_format($t['difference'] / 100, 2)], $r['ties']));
            foreach ($r['problems'] as $p) {
                $this->error($p);
            }
            if ($r['ok']) {
                $this->info("{$tenant->name}: the ledger balances and ties to its sub-ledgers.");
            }
            $ok = $ok && $r['ok'];
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
