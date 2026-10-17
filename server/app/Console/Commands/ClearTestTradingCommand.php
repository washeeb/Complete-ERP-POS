<?php

namespace App\Console\Commands;

use App\Domain\Backup\BackupService;
use App\Domain\Launch\TestTradingReset;
use App\Domain\Support\Actor;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * php artisan erp:clear-test-trading [--apply --confirm="Company name"] — before go-live: show, then remove, the trading
 * done while testing. Products, customers, staff, devices and settings stay. A backup is taken first.
 */
class ClearTestTradingCommand extends Command
{
    protected $signature = 'erp:clear-test-trading {--apply : do it (without this, only show what would go)} {--confirm= : the company name, exactly, to confirm}
        {--skip-backup : do not take the backup first (only when one was just taken)}';

    protected $description = 'Before go-live: remove the test trading (sales, shifts, stock, documents, accounting); keep products, customers, staff and settings';

    public function handle(TestTradingReset $reset, BackupService $backups): int
    {
        $tenant = Tenant::where('is_practice', false)->orderBy('created_at')->first();
        if (! $tenant) {
            $this->error('No company found.');

            return self::FAILURE;
        }
        $counts = $reset->counts($tenant->id);
        $this->info("{$tenant->name}: test trading that would be removed".($counts ? ':' : ': none.'));
        foreach ($counts as $table => $n) {
            $this->line(sprintf('  %-28s %6d', str_replace('_', ' ', $table), $n));
        }
        $this->line('Kept: products, prices, barcodes, customers, suppliers, staff and roles, devices, branches, settings, the audit log.');
        if (! $counts || ! $this->option('apply')) {
            if ($counts) {
                $this->comment("Nothing changed. To do it: --apply --confirm=\"{$tenant->name}\"");
            }

            return self::SUCCESS;
        }
        if ($this->option('confirm') !== $tenant->name) {
            $this->error("Type the company name exactly to confirm: --confirm=\"{$tenant->name}\"");

            return self::FAILURE;
        }
        if (! $this->option('skip-backup')) {
            $this->line('Taking a backup first …');
            try {
                $run = $backups->run('Before clearing test trading');
            } catch (Throwable $e) {
                $this->error('The backup did not run: '.$e->getMessage().' Nothing was cleared.');

                return self::FAILURE;
            }
            if (! in_array($run->status, ['ok', 'partial'], true)) {
                $this->error('The backup failed ('.($run->error ?: 'see System → Backups').'). Nothing was cleared.');

                return self::FAILURE;
            }
            $this->line("  Backup: {$run->file_name}");
        }
        try {
            $done = $reset->clear(Actor::system($tenant->id, 'Clear test trading', 'cli'));
        } catch (Throwable $e) {
            $this->error($e->getMessage().' Nothing was cleared.');

            return self::FAILURE;
        }
        $this->info('Done: '.array_sum($done).' test record(s) removed. Stock is now zero: load the opening stock next.');
        $this->comment('On each till: make sure nothing is waiting to send, then close the till app and open it again.');

        return self::SUCCESS;
    }
}
