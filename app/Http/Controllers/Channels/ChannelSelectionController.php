<?php

namespace App\Http\Controllers\Channels;

use App\Domain\Channels\Models\Channel;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Http\Controllers\Channels\Concerns\PresentsChannels;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The step after a connect that returned accounts the user has not confirmed.
 * Those were saved as pending, which cannot publish. Here the user says which
 * ones to keep; only those become active. Declining, cancelling and waiting
 * too long all discard the pending ones. Channels that were already connected
 * are never changed here.
 */
class ChannelSelectionController extends Controller
{
    use PresentsChannels;

    public function __construct(private readonly CurrentWorkspace $current) {}

    public function edit(Request $request): Response|RedirectResponse
    {
        Gate::authorize('create', Channel::class);

        $selection = $this->selection($request);

        if ($closed = $this->closeIfOver($selection)) {
            return $closed;
        }

        return Inertia::render('channels/Select', [
            'channels' => $selection->waiting()->map($this->present(...))->values()->all(),
            'renewed' => $selection->renewed()->map($this->present(...))->values()->all(),
            'connect_report' => $this->report($request)->present(),
            'minutes' => Channel::PENDING_MINUTES,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('create', Channel::class);

        $selection = $this->selection($request);

        if ($closed = $this->closeIfOver($selection)) {
            return $closed;
        }

        $validated = $request->validate([
            'keep' => ['required', 'array', 'min:1'],
            'keep.*' => ['integer', 'distinct', Rule::in($selection->waiting()->modelKeys())],
        ], [
            'keep.required' => __('Choose at least one to keep, or keep none of them.'),
            'keep.min' => __('Choose at least one to keep, or keep none of them.'),
        ]);

        $confirmed = $selection->resolve(array_map(intval(...), $validated['keep']));
        $this->report($request)->forget();

        $this->toast('success', trans_choice(
            '{1} :count channel is connected.|[2,*] :count channels are connected.',
            $confirmed->count(),
            ['count' => $confirmed->count()],
        ));

        return to_route('channels.index');
    }

    /**
     * Keep none of them.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Gate::authorize('create', Channel::class);

        $selection = $this->selection($request);

        if ($selection->exists()) {
            $selection->abandon();
            $this->report($request)->forget();

            $this->toast('info', __('Nothing new was connected.'));
        }

        return to_route('channels.index');
    }

    private function selection(Request $request): ConnectSelection
    {
        return new ConnectSelection($request->session(), $this->current);
    }

    private function report(Request $request): ConnectReport
    {
        return new ConnectReport($request->session(), $this->current);
    }

    /**
     * Ends a choice that is too old, and sends the user away when there is
     * nothing to choose.
     */
    private function closeIfOver(ConnectSelection $selection): ?RedirectResponse
    {
        if (! $selection->exists()) {
            return to_route('channels.index');
        }

        if ($selection->expired()) {
            $selection->abandon();

            $this->toast('warning', __('The choice was open for too long, so nothing new was connected. Connect again to choose.'));

            return to_route('channels.index');
        }

        if ($selection->waiting()->isEmpty()) {
            $selection->abandon();

            return to_route('channels.index');
        }

        return null;
    }
}
