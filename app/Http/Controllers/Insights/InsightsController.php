<?php

namespace App\Http\Controllers\Insights;

use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Post;
use App\Http\Controllers\Controller;
use App\Http\Presenters\InsightsPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * How the published posts of a period did, for each pillar, each channel
 * and the posts that did best. Read-only: everyone who may see the posts of
 * the workspace may see their numbers. The period and the channel are in
 * the address (?period=7, 28 or 90, ?channel=12), so that a view can be
 * linked. Only someone who can connect channels is told how to change the
 * set-up; everyone else is told whom to ask.
 */
class InsightsController extends Controller
{
    public function __construct(private readonly InsightsPresenter $presenter) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Post::class);

        return Inertia::render('insights/Index', $this->presenter->page(
            InsightsPresenter::periodFrom($request->query('period')),
            InsightsPresenter::channelFrom($request->query('channel')),
            $request->user()?->can('create', Channel::class) ?? false,
        ));
    }
}
