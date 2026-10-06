<?php

namespace App\Console\Commands;

use App\Domain\Finance\AutoPoster;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/** Posts everything not yet in the general ledger (head office; runs every few minutes). */
class GlPostCommand extends Command
{
    protected $signature = 'erp:gl-post';

    protected $description = 'Post sales, stock, invoices, payments, bills, points and gift cards into the general ledger';

    public function handle(AutoPoster $poster): int
    {
        if (config('erp.node.role') === 'branch') {
            $this->info('The general ledger is kept at head office.');

            return self::SUCCESS;
        }
        $failed = false;
        foreach (Tenant::all() as $tenant) {
            app(TenantContext::class)->set($tenant->id);
            $r = $poster->run($tenant->id);
            $this->info("{$tenant->name}: ".(array_sum($r['posted']) ?: 'nothing').' record(s) posted.');
            foreach ($r['errors'] as $kind => $message) {
                $this->error(AutoPoster::KINDS[$kind].": {$message}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
