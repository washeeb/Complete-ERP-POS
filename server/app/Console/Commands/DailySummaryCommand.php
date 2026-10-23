<?php

namespace App\Console\Commands;

use App\Domain\Messaging\DailySummary;
use Illuminate\Console\Command;

/** The daily summary of the business, once a day after the time set (System → Settings → Daily summary). */
class DailySummaryCommand extends Command
{
    protected $signature = 'erp:daily-summary';

    protected $description = 'Send the daily summary of the business when it is due';

    public function handle(DailySummary $summary): int
    {
        $this->info($summary->sendDue().' person(s) told.');

        return self::SUCCESS;
    }
}
