<?php

namespace App\Console\Commands;

use App\Domain\Restaurant\SmsRelay;
use Illuminate\Console\Command;

class ReservationRemindersCommand extends Command
{
    protected $signature = 'erp:reservation-reminders';

    protected $description = 'Queue SMS reminders for restaurant bookings that start soon (sent by erp:relay)';

    public function handle(SmsRelay $sms): int
    {
        $this->info($sms->queueReminders().' reminder(s) queued.');

        return self::SUCCESS;
    }
}
