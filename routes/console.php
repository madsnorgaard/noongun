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

// Channels. Removes accounts that a connect returned and nobody confirmed.
Schedule::command('noongun:discard-pending-channels')->everyFiveMinutes()->withoutOverlapping(10);

// Drafting. Marks draft runs that lost their worker as failed. It never asks
// a provider again.
Schedule::command('noongun:recover-stuck-draft-runs')->everyFiveMinutes()->withoutOverlapping(10);

// Insights. Queues a reading of the numbers of published posts that are due,
// one job per channel. The job decides again what it reads, under a lock.
Schedule::command('noongun:poll-metrics')->everyTenMinutes()->withoutOverlapping(10);
