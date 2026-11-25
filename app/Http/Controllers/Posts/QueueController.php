<?php

namespace App\Http\Controllers\Posts;

use App\Domain\Content\Models\Post;
use App\Domain\Content\PostState;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Posts\Concerns\ActsOnPosts;
use App\Http\Presenters\PostPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The working list: drafts that wait for approval, then what is approved or
 * scheduled in time order, then what failed. Published and rejected posts
 * are the history, on the same page under ?view=history.
 */
class QueueController extends Controller
{
    use ActsOnPosts;

    /** The most posts one section of the working list shows. */
    private const SECTION_LIMIT = 100;

    private const HISTORY_PER_PAGE = 50;

    public function __construct(private readonly PostPresenter $presenter) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Post::class);

        $mayChange = $this->mayChangePosts($request);
        $present = fn (Post $post): array => $this->presenter->present($post, $mayChange);

        if ($request->query('view') === 'history') {
            $history = $this->posts(PostState::Published, PostState::Rejected)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->simplePaginate(self::HISTORY_PER_PAGE)
                ->withQueryString();

            return Inertia::render('queue/Index', [
                'view' => 'history',
                'sections' => null,
                'totals' => null,
                'history' => [
                    'posts' => array_map($present, $history->items()),
                    'previous' => $history->previousPageUrl(),
                    'next' => $history->nextPageUrl(),
                ],
                'can' => ['manage' => $mayChange],
            ]);
        }

        // Soonest planned time first, posts without a time last, oldest
        // first among equals.
        $drafts = $this->posts(PostState::Draft)->orderByRaw('scheduled_at asc nulls last')->orderBy('id');
        $upcoming = $this->posts(PostState::Approved, PostState::Scheduled, PostState::Publishing)->orderByRaw('scheduled_at asc nulls last')->orderBy('id');
        $failed = $this->posts(PostState::Failed)->orderByDesc('updated_at')->orderByDesc('id');

        return Inertia::render('queue/Index', [
            'view' => 'working',
            'sections' => [
                'drafts' => $drafts->limit(self::SECTION_LIMIT)->get()->map($present)->all(),
                'upcoming' => $upcoming->limit(self::SECTION_LIMIT)->get()->map($present)->all(),
                'failed' => $failed->limit(self::SECTION_LIMIT)->get()->map($present)->all(),
            ],
            // How many posts each section really has. A section shows at
            // most SECTION_LIMIT of them, and the page says when it is cut.
            'totals' => [
                'drafts' => Post::query()->inState(PostState::Draft)->count(),
                'upcoming' => Post::query()->inState(PostState::Approved, PostState::Scheduled, PostState::Publishing)->count(),
                'failed' => Post::query()->inState(PostState::Failed)->count(),
            ],
            'history' => null,
            'can' => ['manage' => $mayChange],
        ]);
    }

    /**
     * @return Builder<Post>
     */
    private function posts(PostState ...$states): Builder
    {
        return Post::query()->with(['variants.channel', 'approver'])->inState(...$states);
    }
}
