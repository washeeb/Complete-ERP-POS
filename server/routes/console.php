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
Schedule::command('erp:imports')->everyMinute()->runInBackground(); // large imports waiting in the background (its own lock: one at a time)

// Live updates, store pushes and incoming store orders (each run loops ~55 s).
Schedule::command('erp:relay')->everyMinute()->withoutOverlapping(2)->runInBackground();
Schedule::command('erp:channels:reconcile')->everyFifteenMinutes()->withoutOverlapping(30)->runInBackground();
Schedule::command('erp:access-requests')->hourly(); // IT Admin's access requests not answered in 72 hours lapse
Schedule::command('erp:device-health')->everyFifteenMinutes()->withoutOverlapping(10); // tills with problems: IT is told once a day per problem
Schedule::command('erp:expire-balances')->dailyAt('02:30')->withoutOverlapping(60);
Schedule::command('erp:gl-post')->everyMinute()->withoutOverlapping(30)->runInBackground(); // the books follow sales and stock within a minute
Schedule::command('license:checkin')->dailyAt('03:15')->withoutOverlapping(30);
Schedule::command('license:health')->hourlyAt(20)->withoutOverlapping(30); // system health (no business figures) to the support console
Schedule::command('erp:reservation-reminders')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('erp:appointment-reminders')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('erp:bill-memberships')->dailyAt('01:30')->withoutOverlapping(60); // head office only
Schedule::command('erp:supplier-documents')->dailyAt('06:15')->withoutOverlapping(30); // supplier documents expiring or expired: buyers told once
Schedule::command('erp:insights')->dailyAt('04:30')->withoutOverlapping(120); // forecasts and alerts (no AI model needed)
Schedule::command('erp:reorder-levels --smart')->weeklyOn(1, '05:00')->withoutOverlapping(120); // smart minimum / maximum from sales (Mondays)
Schedule::command('erp:whatsapp-media')->everyMinute()->withoutOverlapping(10); // WhatsApp orders: photos not fetched when they came
Schedule::command('erp:refill-reminders')->everyFifteenMinutes()->withoutOverlapping(30); // customers' medicines running out (head office)
Schedule::command('erp:daily-summary')->everyFiveMinutes()->withoutOverlapping(10); // the owner's evening summary after its time
Schedule::command('erp:payment-summary')->everyFiveMinutes()->withoutOverlapping(10); // daily MoMo / card summary after its time
Schedule::command('erp:pharmacy-signoffs')->dailyAt('19:00')->withoutOverlapping(30); // medicine sales without a pharmacist: reminder or summary
Schedule::command('vendor:license-reminders')->dailyAt('08:00')->withoutOverlapping(30); // licence server only: renewal reminders
Schedule::command('license:sync-prune')->dailyAt('04:10')->withoutOverlapping(30); // licence server only: used nonces and old push answers
Schedule::command('erp:iot')->everyMinute()->withoutOverlapping(5); // sensors: silent ones, late alarms, reminders, sensor clouds (head office, add-on)
Schedule::command('erp:service')->everyFiveMinutes()->withoutOverlapping(10); // service desk deadlines, maintenance plans due (head office, add-on)
Schedule::command('erp:crm')->everyFiveMinutes()->withoutOverlapping(10); // CRM: follow-up reminders, scheduled campaigns (head office, add-on)
Schedule::command('erp:pricing-rules')->hourlyAt(40)->withoutOverlapping(30); // selling prices follow deliveries and freight (head office, incl. synced from branches)
Schedule::command('erp:reorder-levels --alerts')->dailyAt('07:00')->withoutOverlapping(60); // low-stock message to buyers
Schedule::command('erp:reports')->everyFifteenMinutes()->withoutOverlapping(30); // scheduled reports by e-mail / WhatsApp
Schedule::command('erp:backup')->dailyAt(config('backup.time', '02:00'))->withoutOverlapping(240); // encrypted backup to every destination
Schedule::command('erp:backup --verify')->weeklyOn(7, '04:00')->withoutOverlapping(240); // weekly restore check
Schedule::command('erp:evat-batch')->everyFifteenMinutes()->withoutOverlapping(30); // GRA E-VAT daily batch (daily mode)
Schedule::command('erp:health --beat')->everyMinute(); // heartbeat for System → Health
Schedule::command('erp:health-watch')->hourly()->withoutOverlapping(30); // tells IT and the owner when a health check turns red
Schedule::command('erp:pilot-report')->everyFifteenMinutes()->withoutOverlapping(30); // evening pilot report while a pilot runs

// The slow-pages list in System → Health keeps 30 days.
Schedule::call(fn () => DB::table('slow_requests')->where('created_at', '<', now()->subDays(30))->delete())->dailyAt('03:45')->name('prune-slow-requests');
