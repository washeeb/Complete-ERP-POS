<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Node sync with head office / branch servers (does nothing until peers are configured).
Schedule::command('erp:sync')->everyMinute()->withoutOverlapping(10)->runInBackground();

// Live updates, store pushes and incoming store orders (each run loops ~55 s).
Schedule::command('erp:relay')->everyMinute()->withoutOverlapping(2)->runInBackground();
Schedule::command('erp:channels:reconcile')->everyFifteenMinutes()->withoutOverlapping(30)->runInBackground();
Schedule::command('erp:expire-balances')->dailyAt('02:30')->withoutOverlapping(60);
Schedule::command('erp:gl-post')->everyMinute()->withoutOverlapping(30)->runInBackground(); // the books follow sales and stock within a minute
Schedule::command('license:checkin')->dailyAt('03:15')->withoutOverlapping(30);
Schedule::command('erp:reservation-reminders')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('erp:appointment-reminders')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('erp:bill-memberships')->dailyAt('01:30')->withoutOverlapping(60); // head office only
Schedule::command('erp:insights')->dailyAt('04:30')->withoutOverlapping(120); // forecasts and alerts (no AI model needed)
Schedule::command('erp:reorder-levels --smart')->weeklyOn(1, '05:00')->withoutOverlapping(120); // smart minimum / maximum from sales (Mondays)
Schedule::command('erp:pharmacy-signoffs')->dailyAt('19:00')->withoutOverlapping(30); // medicine sales without a pharmacist: reminder or summary
Schedule::command('vendor:license-reminders')->dailyAt('08:00')->withoutOverlapping(30); // licence server only: renewal reminders
Schedule::command('erp:reorder-levels --alerts')->dailyAt('07:00')->withoutOverlapping(60); // low-stock message to buyers
Schedule::command('erp:reports')->everyFifteenMinutes()->withoutOverlapping(30); // scheduled reports by e-mail / WhatsApp
Schedule::command('erp:backup')->dailyAt(config('backup.time', '02:00'))->withoutOverlapping(240); // encrypted backup to every destination
Schedule::command('erp:backup --verify')->weeklyOn(7, '04:00')->withoutOverlapping(240); // weekly restore check
Schedule::command('erp:evat-batch')->everyFifteenMinutes()->withoutOverlapping(30); // GRA E-VAT daily batch (daily mode)
Schedule::command('erp:health --beat')->everyMinute(); // heartbeat for System → Health
Schedule::command('erp:pilot-report')->everyFifteenMinutes()->withoutOverlapping(30); // evening pilot report while a pilot runs

// The slow-pages list in System → Health keeps 30 days.
Schedule::call(fn () => DB::table('slow_requests')->where('created_at', '<', now()->subDays(30))->delete())->dailyAt('03:45')->name('prune-slow-requests');
