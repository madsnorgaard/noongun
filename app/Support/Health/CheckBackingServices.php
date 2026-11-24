<?php

namespace App\Support\Health;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Makes /up fail when PostgreSQL or Redis cannot be reached, so the
 * container health check and Uptime Kuma report a real outage.
 */
class CheckBackingServices
{
    public function handle(): void
    {
        DB::connection()->select('select 1');
        Redis::connection()->ping();
    }
}
