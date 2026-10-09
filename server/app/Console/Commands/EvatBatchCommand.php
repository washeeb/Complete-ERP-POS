<?php

namespace App\Console\Commands;

use App\Domain\Fiscal\FiscalService;
use App\Models\Tenant;
use App\Support\Settings;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/** GRA E-VAT daily mode: once the day's batch time has passed, sends the till sales still waiting (safe to run often). */
class EvatBatchCommand extends Command
{
    protected $signature = 'erp:evat-batch {--now : Send whatever is waiting, regardless of the batch time}';

    protected $description = 'Send the daily GRA E-VAT batch of till sales (daily mode)';

    public function handle(FiscalService $fiscal, Settings $settings): int
    {
        if (config('erp.node.role') === 'branch') {
            $this->info('Branch server: head office reports to GRA.');

            return self::SUCCESS;
        }
        foreach (Tenant::all() as $tenant) {
            app(TenantContext::class)->set($tenant->id);
            if ($fiscal->mode($tenant->id) !== 'daily') {
                continue;
            }
            $local = now($tenant->timezone ?: 'UTC');
            if (! $this->option('now') && $local->format('H:i') < (string) $settings->get('evat.daily_time', $tenant->id)) {
                continue;
            }
            $this->info("{$tenant->name}: ".$fiscal->batch($tenant->id, $local->toDateString()).' sale(s) sent to GRA.');
        }

        return self::SUCCESS;
    }
}
