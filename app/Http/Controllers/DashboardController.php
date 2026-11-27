<?php

namespace App\Http\Controllers;

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Post;
use App\Domain\Drafting\DraftGenerators;
use App\Domain\Drafting\Models\Brief;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Scheduling\Models\Slot;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use App\Http\Presenters\InsightsPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, CurrentWorkspace $current, DraftGenerators $generators, InsightsPresenter $insights): Response
    {
        $workspace = $current->get();
        $steps = $workspace !== null ? $this->setupSteps($workspace, $generators) : [];

        return Inertia::render('Dashboard', [
            // What a new workspace has to do before a week can be proposed,
            // in order. Null once all of it is done, and the list goes.
            'setup' => $steps === [] || ! in_array(false, array_column($steps, 'done'), true) ? null : $steps,
            // What the last week added up to and its best post. Null when
            // there is nothing to show, and the block goes.
            'insights' => $workspace !== null && ($request->user()?->can('viewAny', Post::class) ?? false)
                ? $insights->lastWeek()
                : null,
            'can' => [
                'propose' => Route::has('proposals.create')
                    && ($request->user()?->can('create', DraftRun::class) ?? false),
            ],
        ]);
    }

    /**
     * @return list<array{key: string, done: bool}>
     */
    private function setupSteps(Workspace $workspace, DraftGenerators $generators): array
    {
        return [
            ['key' => 'channel', 'done' => Channel::query()->whereNotIn('status', [ChannelStatus::Disconnected, ChannelStatus::Pending])->exists()],
            ['key' => 'brief', 'done' => Brief::current() !== null],
            ['key' => 'slots', 'done' => Slot::query()->exists()],
            ['key' => 'key', 'done' => $generators->availableFor($workspace)],
            ['key' => 'propose', 'done' => DraftRun::query()->exists()],
        ];
    }
}
