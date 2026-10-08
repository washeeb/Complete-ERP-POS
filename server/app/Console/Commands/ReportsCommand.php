<?php

namespace App\Console\Commands;

use App\Domain\Reports\ReportService;
use Illuminate\Console\Command;

/** Sends the scheduled reports that are due (head office only: branch servers do not send reports). */
class ReportsCommand extends Command
{
    protected $signature = 'erp:reports';

    protected $description = 'Run and send scheduled reports that are due';

    public function handle(ReportService $reports): int
    {
        if (config('erp.node.role') === 'branch') {
            $this->info('Branch server: head office sends scheduled reports.');

            return self::SUCCESS;
        }
        $this->info($reports->runDue().' report(s) sent.');

        return self::SUCCESS;
    }
}
