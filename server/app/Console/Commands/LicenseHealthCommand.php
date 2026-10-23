<?php

namespace App\Console\Commands;

use App\Domain\Licensing\HealthReport;
use Illuminate\Console\Command;

/** Hourly: the system's health (no business figures) to SyncLytics' support console, unless the owner switched it off. */
class LicenseHealthCommand extends Command
{
    protected $signature = 'license:health';

    protected $description = 'Send the hourly health report to the support console (head office)';

    public function handle(HealthReport $report): int
    {
        $r = $report->send();
        $r['ok'] ? $this->info($r['message']) : $this->warn($r['message']);

        return self::SUCCESS;
    }
}
