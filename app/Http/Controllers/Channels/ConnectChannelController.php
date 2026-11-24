<?php

namespace App\Http\Controllers\Channels;

use App\Domain\Channels\ChannelManager;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Exceptions\ChannelException;
use App\Domain\Channels\Models\Channel;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Http\Controllers\Channels\Concerns\PresentsChannels;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The OAuth round trip for connecting a channel. It only talks to
 * ChannelManager and ChannelAdapter; the adapter makes the platform calls.
 */
class ConnectChannelController extends Controller
{
    use PresentsChannels;

    /** Session key of the connection that is under way. */
    public const PENDING = 'channels.connect';

    /** How long the user has to come back from the platform. */
    private const STATE_MINUTES = 15;

    public function __construct(
        private readonly ChannelManager $manager,
        private readonly CurrentWorkspace $current,
    ) {}

    /**
     * Sends the browser to the platform. The state value is random, kept in
     * the session, and tied to the platform and the workspace, so a callback
     * is only accepted in the browser session that started it.
     */
    public function store(Request $request, string $platform): Response
    {
        Gate::authorize('create', Channel::class);

        $adapter = $this->adapter($platform);
        $workspace = $this->current->getOrFail();
        $state = Str::random(40);

        try {
            $url = $adapter->authorizationUrl($workspace, $state);
        } catch (ChannelException $exception) {
            $this->toast('error', $exception->getMessage());

            return to_route('channels.index');
        }

        $request->session()->put(self::PENDING, [
            'platform' => $platform,
            'state' => $state,
            'workspace_id' => $workspace->id,
            'expires_at' => now()->addMinutes(self::STATE_MINUTES)->getTimestamp(),
        ]);

        return Inertia::location($url);
    }

    /**
     * Where the platform sends the browser back to, with a code or an error.
     */
    public function callback(Request $request, string $platform): RedirectResponse
    {
        Gate::authorize('create', Channel::class);

        $adapter = $this->adapter($platform);
        $workspace = $this->current->getOrFail();
        $label = $this->platformLabel($platform);

        // Used once: taken out of the session whatever the outcome.
        $pending = $request->session()->pull(self::PENDING);

        if (! $this->stateIsValid($pending, $platform, $workspace->id, $request->query('state'))) {
            $this->toast('error', __('The connection could not be verified. Start again from this page.'));

            return to_route('channels.index');
        }

        if ($request->query('error') !== null) {
            $request->query('error') === 'access_denied'
                ? $this->toast('info', __('Access was not granted on :platform. Nothing was connected.', ['platform' => $label]))
                : $this->toast('error', __(':platform reported a problem during sign-in. Nothing was connected.', ['platform' => $label]));

            return to_route('channels.index');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            $this->toast('error', __(':platform did not send an authorization code. Nothing was connected.', ['platform' => $label]));

            return to_route('channels.index');
        }

        try {
            $channels = $adapter->connect($workspace, $code);
        } catch (ChannelException $exception) {
            report($exception);
            $this->toast('error', $exception->getMessage());

            return to_route('channels.index');
        }

        if ($channels->count() > 1) {
            $request->session()->put(ChannelSelectionController::PENDING, [
                'workspace_id' => $workspace->id,
                'channel_ids' => $channels->map(fn (Channel $channel): int => $channel->id)->values()->all(),
            ]);

            return to_route('channels.selection.edit');
        }

        $this->toast('success', __(':name is connected.', ['name' => $channels->first()->display_name ?? $label]));

        return to_route('channels.index');
    }

    private function adapter(string $platform): ChannelAdapter
    {
        abort_unless($this->manager->has($platform), 404);

        return $this->manager->adapter($platform);
    }

    private function stateIsValid(mixed $pending, string $platform, int $workspaceId, mixed $state): bool
    {
        return is_array($pending)
            && is_string($state)
            && is_string($pending['state'] ?? null)
            && hash_equals($pending['state'], $state)
            && ($pending['platform'] ?? null) === $platform
            && ($pending['workspace_id'] ?? null) === $workspaceId
            && is_int($pending['expires_at'] ?? null)
            && $pending['expires_at'] >= now()->getTimestamp();
    }
}
