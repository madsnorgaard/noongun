<?php

namespace App\Http\Controllers\Channels;

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Models\Channel;
use App\Domain\Workspaces\CurrentWorkspace;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Collection;

/**
 * The choice that is open after a connect returned accounts the user has not
 * confirmed. It lives in the session and names the pending channels of that
 * one connect, so it can never reach any other channel.
 *
 * It ends when the user chooses, when the user cancels, when a new connect
 * starts, or when it is older than Channel::PENDING_MINUTES. However it ends,
 * the channels that were not kept are discarded: nothing stays connected
 * that the user did not confirm.
 */
final class ConnectSelection
{
    public const KEY = 'channels.selection';

    public function __construct(
        private readonly Session $session,
        private readonly CurrentWorkspace $current,
    ) {}

    /**
     * @param  array<int>  $pendingIds  channels waiting for the user
     * @param  array<int>  $renewedIds  channels the same connect renewed, shown for context only
     */
    public function open(array $pendingIds, array $renewedIds): void
    {
        $this->session->put(self::KEY, [
            'workspace_id' => $this->current->id(),
            'channel_ids' => array_values($pendingIds),
            'renewed_ids' => array_values($renewedIds),
            'expires_at' => now()->addMinutes(Channel::PENDING_MINUTES)->getTimestamp(),
        ]);
    }

    /**
     * True when a choice is open for the current workspace, even if it has
     * expired and only waits to be cleared.
     */
    public function exists(): bool
    {
        return $this->ids('channel_ids') !== [];
    }

    public function expired(): bool
    {
        $expiresAt = $this->entry()['expires_at'] ?? null;

        return ! is_int($expiresAt) || $expiresAt < now()->getTimestamp();
    }

    /**
     * The channels still waiting for the user. Only rows that are pending
     * count, whatever the session says, so a stale or tampered entry cannot
     * touch a channel that is connected or disconnected.
     *
     * @return Collection<int, Channel>
     */
    public function waiting(): Collection
    {
        $ids = $this->ids('channel_ids');

        if ($ids === []) {
            return new Collection;
        }

        return Channel::query()
            ->whereKey($ids)
            ->where('status', ChannelStatus::Pending)
            ->orderBy('display_name')
            ->get();
    }

    /**
     * The channels the same connect renewed without asking.
     *
     * @return Collection<int, Channel>
     */
    public function renewed(): Collection
    {
        $ids = $this->ids('renewed_ids');

        if ($ids === []) {
            return new Collection;
        }

        return Channel::query()->whereKey($ids)->orderBy('display_name')->get();
    }

    /**
     * Keeps the given channels, discards the other waiting ones and closes
     * the choice. Returns the channels that became active.
     *
     * @param  array<int>  $keep
     * @return Collection<int, Channel>
     */
    public function resolve(array $keep): Collection
    {
        [$kept, $declined] = $this->waiting()->partition(fn (Channel $channel): bool => in_array($channel->id, $keep, true));

        $declined->each(fn (Channel $channel) => $channel->discardPending());
        $confirmed = $kept->filter(fn (Channel $channel): bool => $channel->confirm())->values();

        $this->session->forget(self::KEY);

        return $confirmed;
    }

    /**
     * Closes the choice without keeping anything. Returns how many waiting
     * channels were discarded.
     */
    public function abandon(): int
    {
        $discarded = $this->waiting()->count();

        $this->resolve([]);

        return $discarded;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function entry(): array
    {
        $entry = $this->session->get(self::KEY);

        // An entry from another workspace of the same user is not this one's.
        return is_array($entry) && ($entry['workspace_id'] ?? null) === $this->current->id() ? $entry : [];
    }

    /**
     * @return list<int>
     */
    private function ids(string $key): array
    {
        $ids = $this->entry()[$key] ?? [];

        return array_values(array_filter(is_array($ids) ? $ids : [], is_int(...)));
    }
}
