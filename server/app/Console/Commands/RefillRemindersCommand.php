<?php

namespace App\Console\Commands;

use App\Domain\Pharmacy\RefillReminders;
use Illuminate\Console\Command;

/** Refill reminders: new ones from the latest sales, and those due today (System → Settings → Refill reminders). */
class RefillRemindersCommand extends Command
{
    protected $signature = 'erp:refill-reminders';

    protected $description = 'Make refill reminders from sales of daily medicines and send those due';

    public function handle(RefillReminders $reminders): int
    {
        $r = $reminders->run();
        $this->info("{$r['made']} reminder(s) made, {$r['sent']} customer(s) reminded.");

        return self::SUCCESS;
    }
}
