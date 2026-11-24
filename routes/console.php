<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Publishing. Both commands are safe to run twice at once; the overlap guard
// only saves work. Its lock expires after a few minutes, so a run that was
// killed does not hold the next ones back for long.
Schedule::command('noongun:dispatch-due-posts')->everyMinute()->withoutOverlapping(5);
Schedule::command('noongun:recover-stuck-posts')->everyFiveMinutes()->withoutOverlapping(10);
