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
