<?php

namespace App\Domain\Channels\Contracts;

use App\Domain\Channels\Exceptions\ChannelException;
use App\Domain\Channels\Exceptions\PublishFailed;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * One implementation per platform, resolved through ChannelManager. This is
 * the only place that talks to a platform API: controllers, jobs and UI call
 * these methods and never the vendor directly.
 *
 * Adapters are stateless. ChannelManager builds each one through the
 * container with a named constructor argument `array $settings`, holding the
 * platform's entry from config('noongun.channels') without the `adapter` key.
 *
 * Every method that reads or writes Channel or PostVariant rows expects the
 * owning workspace to be the current workspace (CurrentWorkspace::run() in
 * jobs and commands).
 *
 * Failures are reported as ChannelException (PublishFailed for publish()).
 * Their messages and context must never contain tokens, secrets or
 * authorization codes.
 *
 * What only some platforms can do is not in this interface. It is a second
 * interface the adapter also implements: DescribesSetup,
 * ExplainsMissingAccounts, and ReadsMetrics for fetchMetrics() (ADR 0016).
 */
interface ChannelAdapter
{
    /**
     * The platform name, equal to this adapter's key in
     * config('noongun.channels') and to Channel::$platform.
     */
    public function platform(): string;

    public function capabilities(): ChannelCapabilities;

    /**
     * The URL the user's browser is sent to in order to grant access. $state
     * is the caller's CSRF value and is returned unchanged by the platform.
     */
    public function authorizationUrl(Workspace $workspace, string $state): string;

    /**
     * Exchanges the authorization code and saves one Channel per account the
     * grant gives access to (for Facebook: one per Page). Existing rows are
     * updated, matched on platform and external_id, so that connecting again
     * renews tokens instead of creating duplicates.
     *
     * Adapters save through Channel::recordConnection(), which decides the
     * status. A channel the user already has connected is renewed and stays
     * active. Every other account, new or disconnected earlier, is saved as
     * pending: it holds a token but cannot publish until the caller has had
     * the user confirm it (Channel::confirm()). What is not confirmed is
     * discarded.
     *
     * An adapter that left out accounts the platform listed, for example
     * because the user's role there does not allow publishing, returns a
     * ConnectedChannels, which is this collection with those accounts and
     * the reason for each. A plain Collection means nothing was left out.
     * When no account can be connected at all, it throws NothingToConnect,
     * which carries the same list.
     *
     * @return Collection<int, Channel> the saved channels
     *
     * @throws ChannelException
     */
    public function connect(Workspace $workspace, string $code): Collection;

    /**
     * Makes exactly one publishing call for the variant and returns what the
     * platform reported. It does not write to the variant: the caller holds
     * the lock, has checked external_id, and records the result with
     * PostVariant::markPublished().
     *
     * A failure is retryable only when the platform certainly did not create
     * the post, for example a rate limit refusal. When the post may exist
     * (a timeout after the request was sent, a server error, an answer that
     * cannot be read) the failure must not be retryable: the caller resends
     * automatically, and a second public post is the worst outcome there is.
     *
     * @throws PublishFailed with retryable set only when the platform certainly published nothing
     */
    public function publish(PostVariant $variant): PublishResult;

    /**
     * Removes a published variant from the platform. Does not touch the row.
     *
     * @throws ChannelException
     */
    public function delete(PostVariant $variant): void;

    /**
     * Renews the channel's token where the platform supports it, saves and
     * returns the channel. Where a token cannot be renewed the adapter marks
     * the channel with Channel::markNeedsReconnect() and returns it.
     *
     * @throws ChannelException
     */
    public function refreshToken(Channel $channel): Channel;
}
