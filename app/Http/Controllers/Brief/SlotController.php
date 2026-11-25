<?php

namespace App\Http\Controllers\Brief;

use App\Domain\Channels\Models\Channel;
use App\Domain\Scheduling\Models\Slot;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Http\Controllers\Brief\Concerns\ActsAsMember;
use App\Http\Controllers\Controller;
use App\Http\Presenters\BriefPresenter;
use App\Http\Requests\Brief\StoreSlotRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Preferred posting times per channel. A slot is a weekday and a time on the
 * clock of the workspace. It proposes a time for a draft and schedules
 * nothing.
 */
class SlotController extends Controller
{
    use ActsAsMember;

    public function index(Request $request, CurrentWorkspace $current, BriefPresenter $presenter): Response
    {
        Gate::authorize('viewAny', Slot::class);

        $workspace = $current->getOrFail();
        $slots = Slot::query()->orderBy('weekday')->orderBy('time')->orderBy('id')->get();

        // A channel is listed when a slot can be added to it, or when it
        // still has slots from before it was disconnected.
        $channels = Channel::query()
            ->where(fn ($query) => $query
                ->whereNotIn('status', StoreSlotRequest::NOT_OFFERED)
                ->orWhereIn('id', $slots->pluck('channel_id')->unique()->all()))
            ->orderBy('platform')
            ->orderBy('display_name')
            ->get();

        return Inertia::render('brief/Slots', [
            'channels' => $channels->map(fn (Channel $channel): array => [
                'id' => $channel->id,
                'display_name' => $channel->display_name,
                'platform_label' => $presenter->platformLabel($channel->platform),
                'offered' => ! in_array($channel->status, StoreSlotRequest::NOT_OFFERED, true),
                'can_publish' => $channel->canPublish(),
            ])->all(),
            'slots' => $slots->map(fn (Slot $slot): array => [
                'id' => $slot->id,
                'channel_id' => $slot->channel_id,
                'weekday' => $slot->weekday,
                'time' => $slot->time,
                'next_at' => $slot->setRelation('workspace', $workspace)->nextOccurrence()->toIso8601String(),
            ])->all(),
            'can' => [
                'manage' => $this->mayWrite($request, Slot::class),
            ],
        ]);
    }

    public function store(StoreSlotRequest $request): RedirectResponse
    {
        $added = 0;

        foreach ($request->weekdays() as $weekday) {
            $slot = Slot::query()->firstOrCreate([
                'channel_id' => $request->channelId(),
                'weekday' => $weekday,
                'time' => $request->time(),
            ]);

            $added += $slot->wasRecentlyCreated ? 1 : 0;
        }

        if ($added === 0) {
            $this->toast('info', __('Nothing was added. The channel already has these slots.'));
        } else {
            $this->toast('success', trans_choice('{1} The slot is added.|[2,*] :count slots are added.', $added, ['count' => $added]));
        }

        return to_route('slots.index');
    }

    public function destroy(int $slot): RedirectResponse
    {
        // Looked up here, not by route binding: the workspace scope needs the
        // current workspace, which is set after bindings are resolved.
        $slot = Slot::query()->findOrFail($slot);

        Gate::authorize('delete', $slot);

        $slot->delete();

        $this->toast('success', __('The slot is removed. Posts that already have a time keep it.'));

        return to_route('slots.index');
    }
}
