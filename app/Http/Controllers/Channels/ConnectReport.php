<?php

namespace App\Http\Controllers\Channels;

use App\Domain\Channels\Contracts\SkippedAccount;
use App\Domain\Channels\Models\Channel;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Http\Presenters\PlatformLabel;
use Illuminate\Contracts\Session\Session;

/**
 * What the last connect of this session left out: the accounts the platform
 * listed that could not be connected, and the platform's hint about accounts
 * it does not list at all. It is information about one connect attempt, so it
 * lives in the session, belongs to one workspace and ends with the next
 * connect or after Channel::PENDING_MINUTES. It never holds a token.
 */
final class ConnectReport
{
    public const KEY = 'channels.connect_report';

    public function __construct(
        private readonly Session $session,
        private readonly CurrentWorkspace $current,
    ) {}

    /**
     * @param  list<SkippedAccount>  $skipped
     */
    public function record(string $platform, array $skipped, ?string $hint): void
    {
        if ($skipped === [] && $hint === null) {
            $this->forget();

            return;
        }

        $this->session->put(self::KEY, [
            'workspace_id' => $this->current->id(),
            'platform' => $platform,
            'skipped' => array_map(fn (SkippedAccount $account): array => $account->toArray(), $skipped),
            'hint' => $hint,
            'expires_at' => now()->addMinutes(Channel::PENDING_MINUTES)->getTimestamp(),
        ]);
    }

    /**
     * What the pages show, or null when there is nothing for this workspace.
     *
     * @return array{platform_label: string, skipped: list<array{id: string, name: string, reason: string}>, hint: string|null}|null
     */
    public function present(): ?array
    {
        $entry = $this->session->get(self::KEY);

        if (! is_array($entry)
            || ($entry['workspace_id'] ?? null) !== $this->current->id()
            || ! is_string($entry['platform'] ?? null)
            || ! is_int($entry['expires_at'] ?? null)
            || $entry['expires_at'] < now()->getTimestamp()) {
            return null;
        }

        $skipped = [];

        foreach (is_array($entry['skipped'] ?? null) ? $entry['skipped'] : [] as $account) {
            if (($account = SkippedAccount::fromArray($account)) !== null) {
                $skipped[] = $account->toArray();
            }
        }

        return [
            'platform_label' => PlatformLabel::for($entry['platform']),
            'skipped' => $skipped,
            'hint' => is_string($entry['hint'] ?? null) ? $entry['hint'] : null,
        ];
    }

    /**
     * The same, shown for the last time.
     *
     * @return array{platform_label: string, skipped: list<array{id: string, name: string, reason: string}>, hint: string|null}|null
     */
    public function presentOnce(): ?array
    {
        $report = $this->present();

        if ($report !== null) {
            $this->forget();
        }

        return $report;
    }

    public function forget(): void
    {
        $this->session->forget(self::KEY);
    }
}
