<?php

namespace App\Domain\Insights;

use App\Domain\Channels\Events\ChannelConnected;
use Illuminate\Support\Facades\DB;

/**
 * A channel was connected again, so what reading metrics concluded about
 * its old token no longer holds:
 *
 * - the pause that was set because the token was not accepted ends now,
 *   instead of hours later. A wait the platform asked for stays.
 * - posts that were reported as gone are asked about again. A platform
 *   gives the same answer for a deleted post and for one the token may not
 *   see, and the new token may see it. A post that is really gone is
 *   counted out again after a few readings.
 */
final class ResumePollingOfConnectedChannel
{
    public function handle(ChannelConnected $event): void
    {
        ChannelPolling::connectedAgain($event->channelId);

        DB::table('post_variants')
            ->where('workspace_id', $event->workspaceId)
            ->where('channel_id', $event->channelId)
            ->where('metrics_gone_count', '>', 0)
            ->update(['metrics_gone_count' => 0]);
    }
}
