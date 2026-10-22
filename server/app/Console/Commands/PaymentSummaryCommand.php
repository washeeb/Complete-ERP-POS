<?php

namespace App\Console\Commands;

use App\Domain\Messaging\PaymentAlerts;
use Illuminate\Console\Command;

/** The day's mobile money and card summary, once a day after the time set (System → Settings → Payment alerts). */
class PaymentSummaryCommand extends Command
{
    protected $signature = 'erp:payment-summary';

    protected $description = 'Send the daily mobile money and card payment summary when it is due';

    public function handle(PaymentAlerts $alerts): int
    {
        $this->info($alerts->dailySummaries().' person(s) told.');

        return self::SUCCESS;
    }
}
