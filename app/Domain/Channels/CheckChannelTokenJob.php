<?php

namespace App\Domain\Channels;

use App\Domain\Channels\Events\ChannelTokenVerified;
use App\Domain\Channels\Exceptions\ChannelAdapterNotAvailable;
use App\Domain\Channels\Exceptions\ChannelException;
use App\Domain\Channels\Models\Channel;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks the platform whether the token of one active channel is still good
 * (ChannelAdapter::refreshToken()), and records what it reports: the
 * permissions and the expiry, or a renewed token where the platform gives
 * one. Queued by noongun:check-channel-tokens.
 *
 * This is the one place outside publishing that can take a channel out of
 * service, so it only does that on a definite answer. The adapter marks the
 * channel as needing a reconnect when the platform says the token is not
 * valid, has expired, or lacks a permission that publishing needs. Every
 * other outcome leaves the channel exactly as it was: an outage, a rate
 * limit, an answer that cannot be read, a platform that is not configured,
 * an exception. Those are logged and the channel is asked about again at
 * the next round.
 *
 * The row of the channel is locked while the platform is asked, so a
 * connect that happens at the same moment is not overwritten by the answer
 * about the token it replaced: the connect waits, then wins. A channel that
 * is not active is left alone, and a channel that needs a reconnect is
 * never made active here.
 *
 * It carries two ids and nothing else. No token is in the payload or in a
 * log line.
 */
final class CheckChannelTokenJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $workspaceId,
        public int $channelId,
    ) {
        $this->timeout = TokenCheckConfig::jobSeconds();
        $this->onQueue(TokenCheckConfig::queue());
    }

    public function uniqueId(): string
    {
        return $this->workspaceId.':'.$this->channelId;
    }

    public function uniqueFor(): int
    {
        return TokenCheckConfig::UNIQUE_SECONDS;
    }

    public function handle(CurrentWorkspace $currentWorkspace, ChannelManager $channels): void
    {
        if (! TokenCheckConfig::enabled()) {
            return;
        }

        $workspace = Workspace::query()->find($this->workspaceId);

        if ($workspace === null) {
            return;
        }

        $currentWorkspace->run($workspace, function () use ($channels): void {
            $this->check($channels);
        });
    }

    private function check(ChannelManager $channels): void
    {
        $verified = false;
        $asked = false;

        try {
            DB::transaction(function () use ($channels, &$verified, &$asked): void {
                $channel = Channel::query()->lockForUpdate()->find($this->channelId);

                if ($channel === null || $channel->status !== ChannelStatus::Active) {
                    return;
                }

                $asked = true;
                $checked = $channels->for($channel)->refreshToken($channel);

                if ($checked->status === ChannelStatus::NeedsReconnect) {
                    Log::notice('The platform no longer accepts the token of a channel. It is marked as needing a reconnect.', [
                        'workspace_id' => $this->workspaceId,
                        'channel_id' => $this->channelId,
                        'platform' => $channel->platform,
                    ]);

                    return;
                }

                $verified = $checked->status === ChannelStatus::Active;
            });
        } catch (ChannelAdapterNotAvailable) {
            // The platform is not configured on this instance. Nothing can
            // be asked, and nothing is wrong with the channel.
        } catch (ChannelException $e) {
            Log::warning('The token of a channel could not be checked. The channel is left as it is.', [
                'workspace_id' => $this->workspaceId,
                'channel_id' => $this->channelId,
                'message' => $e->getMessage(),
                ...$e->context(),
            ]);
        } catch (Throwable $e) {
            // The exception itself is not logged: HTTP client exceptions
            // can carry tokens.
            Log::error('Checking the token of a channel threw an unexpected exception. The channel is left as it is.', [
                'workspace_id' => $this->workspaceId,
                'channel_id' => $this->channelId,
                'exception' => $e::class,
            ]);
        }

        if ($asked) {
            // Whatever came of it, the channel waits a full round before it
            // is asked about again. Written without the model, so nothing
            // else on the row moves.
            Channel::query()->whereKey($this->channelId)->toBase()->update(['token_checked_at' => now()]);
        }

        if ($verified) {
            event(new ChannelTokenVerified($this->workspaceId, $this->channelId));
        }
    }
}
