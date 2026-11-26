<?php

namespace App\Http\Controllers\Insights;

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
 * the workspace may see their numbers. The period is in the address
 * (?period=7, 28 or 90), so that a view can be linked.
 */
class InsightsController extends Controller
{
    public function __construct(private readonly InsightsPresenter $presenter) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Post::class);

        return Inertia::render('insights/Index', $this->presenter->page(
            InsightsPresenter::periodFrom($request->query('period')),
        ));
    }
}
