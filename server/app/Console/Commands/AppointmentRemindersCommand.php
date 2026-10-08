<?php

namespace App\Console\Commands;

use App\Domain\Services\AppointmentService;
use Illuminate\Console\Command;

class AppointmentRemindersCommand extends Command
{
    protected $signature = 'erp:appointment-reminders';

    protected $description = 'Queue SMS reminders for appointments that start soon (sent by erp:relay)';

    public function handle(AppointmentService $appointments): int
    {
        $this->info($appointments->queueReminders().' reminder(s) queued.');

        return self::SUCCESS;
    }
}
