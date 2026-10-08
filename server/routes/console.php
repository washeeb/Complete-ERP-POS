<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
Schedule::command('erp:gl-post')->everyFiveMinutes()->withoutOverlapping(30)->runInBackground();
Schedule::command('license:checkin')->dailyAt('03:15')->withoutOverlapping(30);
Schedule::command('erp:reservation-reminders')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('erp:appointment-reminders')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('erp:bill-memberships')->dailyAt('01:30')->withoutOverlapping(60); // head office only
Schedule::command('erp:insights')->dailyAt('04:30')->withoutOverlapping(120); // forecasts and alerts (no AI model needed)
Schedule::command('erp:reports')->everyFifteenMinutes()->withoutOverlapping(30); // scheduled reports by e-mail / WhatsApp
