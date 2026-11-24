<?php

namespace App\Http\Controllers\Channels;

use App\Domain\Channels\ChannelManager;
use App\Domain\Channels\Contracts\DescribesSetup;
use App\Domain\Channels\Models\Channel;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Http\Controllers\Channels\Concerns\PresentsChannels;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ChannelController extends Controller
{
    use PresentsChannels;

    public function index(Request $request, ChannelManager $manager, CurrentWorkspace $current): Response
    {
        Gate::authorize('viewAny', Channel::class);

        // Housekeeping that the scheduler also does: accounts that waited too
        // long for a confirmation are removed before the list is shown.
        Channel::discardExpiredPending();

        $channels = Channel::query()->orderBy('platform')->orderBy('display_name')->get();
        $selection = new ConnectSelection($request->session(), $current);
        $canManage = $request->user()?->can('create', Channel::class) ?? false;

        return Inertia::render('channels/Index', [
            'channels' => $channels->map($this->present(...))->all(),
            'selection_open' => $canManage && ! $selection->expired() && $selection->waiting()->isNotEmpty(),
            'platforms' => array_map(function (string $platform) use ($manager): array {
                $adapter = $manager->adapter($platform);
                $setup = $adapter instanceof DescribesSetup ? $adapter : null;

                return [
                    'name' => $platform,
                    'label' => $this->platformLabel($platform),
                    'missing_settings' => $setup?->missingSettings() ?? [],
                    'redirect_uri' => $setup?->redirectUri(),
                ];
            }, $manager->available()),
            'can' => [
                'manage' => $canManage,
            ],
        ]);
    }

    /**
     * Disconnects the channel. The row stays, so published posts keep their
     * channel; only the tokens are erased.
     */
    public function destroy(int $channel): RedirectResponse
    {
        // Looked up here, not by route binding: the workspace scope needs the
        // current workspace, which is set after bindings are resolved.
        $channel = Channel::query()->findOrFail($channel);

        Gate::authorize('delete', $channel);

        // A pending channel was never connected: it is discarded, not kept
        // as a disconnected row, unless posts already point at it.
        $channel->isPending() ? $channel->discardPending() : $channel->disconnect();

        $this->toast('success', __(':name is disconnected.', ['name' => $channel->display_name]));

        return to_route('channels.index');
    }
}
