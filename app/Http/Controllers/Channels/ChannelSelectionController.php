<?php

namespace App\Http\Controllers\Channels;

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Models\Channel;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Http\Controllers\Channels\Concerns\PresentsChannels;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The step after a connect that returned more than one account. Every account
 * was saved by the adapter; here the user says which ones to keep, and the
 * rest are disconnected, which erases their tokens.
 */
class ChannelSelectionController extends Controller
{
    use PresentsChannels;

    /** Session key of the channels that the last connect returned. */
    public const PENDING = 'channels.selection';

    public function __construct(private readonly CurrentWorkspace $current) {}

    public function edit(Request $request): Response|RedirectResponse
    {
        Gate::authorize('create', Channel::class);

        $channels = $this->pending($request);

        if ($channels->isEmpty()) {
            return to_route('channels.index');
        }

        return Inertia::render('channels/Select', [
            'channels' => $channels->map($this->present(...))->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('create', Channel::class);

        $channels = $this->pending($request);

        if ($channels->isEmpty()) {
            return to_route('channels.index');
        }

        $validated = $request->validate([
            'keep' => ['required', 'array', 'min:1'],
            'keep.*' => ['integer', 'distinct', Rule::in($channels->modelKeys())],
        ], [
            'keep.required' => __('Choose at least one to keep.'),
            'keep.min' => __('Choose at least one to keep.'),
        ]);

        $keep = array_map(intval(...), $validated['keep']);

        $channels->reject(fn (Channel $channel): bool => in_array($channel->id, $keep, true))
            ->each(fn (Channel $channel) => $channel->disconnect());

        $request->session()->forget(self::PENDING);

        $this->toast('success', trans_choice('{1} :count channel is connected.|[2,*] :count channels are connected.', count($keep), ['count' => count($keep)]));

        return to_route('channels.index');
    }

    /**
     * The channels of the last connect that are still waiting for a choice.
     * Scoped to the current workspace by the model, and checked against the
     * workspace the connect ran in.
     *
     * @return Collection<int, Channel>
     */
    private function pending(Request $request): Collection
    {
        $pending = $request->session()->get(self::PENDING);
        $ids = is_array($pending) && is_array($pending['channel_ids'] ?? null) ? $pending['channel_ids'] : [];

        if ($ids === [] || ($pending['workspace_id'] ?? null) !== $this->current->id()) {
            return new Collection;
        }

        return Channel::query()
            ->whereKey($ids)
            ->where('status', ChannelStatus::Active)
            ->orderBy('display_name')
            ->get();
    }
}
