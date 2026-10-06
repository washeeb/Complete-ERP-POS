<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Node sync with head office / branch servers (does nothing until peers are configured).
Schedule::command('erp:sync')->everyMinute()->withoutOverlapping(10)->runInBackground();
