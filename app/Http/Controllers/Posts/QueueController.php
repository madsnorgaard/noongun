<?php

namespace App\Http\Controllers\Posts;

use App\Domain\Content\Models\Post;
use App\Domain\Content\PostState;
use App\Domain\Drafting\Models\DraftRun;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Posts\Concerns\ActsOnPosts;
use App\Http\Presenters\PostPresenter;
use App\Http\Presenters\ProposalPresenter;
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

    public function __construct(
        private readonly PostPresenter $presenter,
        private readonly ProposalPresenter $proposals,
    ) {}

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
                'drafting' => null,
            ]);
        }

        // Soonest planned time first, posts without a time last, oldest
        // first among equals.
        $drafts = $this->posts(PostState::Draft)->orderByRaw('scheduled_at asc nulls last')->orderBy('id');
        $upcoming = $this->posts(PostState::Approved, PostState::Scheduled, PostState::Publishing)->orderByRaw('scheduled_at asc nulls last')->orderBy('id');
        $failed = $this->posts(PostState::Failed)->orderByDesc('updated_at')->orderByDesc('id');

        // The lists are closures so that the page can ask for the progress
        // of a proposal alone, every few seconds, without loading them.
        return Inertia::render('queue/Index', [
            'view' => 'working',
            'sections' => fn (): array => [
                'drafts' => $drafts->limit(self::SECTION_LIMIT)->get()->map($present)->all(),
                'upcoming' => $upcoming->limit(self::SECTION_LIMIT)->get()->map($present)->all(),
                'failed' => $failed->limit(self::SECTION_LIMIT)->get()->map($present)->all(),
            ],
            // How many posts each section really has. A section shows at
            // most SECTION_LIMIT of them, and the page says when it is cut.
            'totals' => fn (): array => [
                'drafts' => Post::query()->inState(PostState::Draft)->count(),
                'upcoming' => Post::query()->inState(PostState::Approved, PostState::Scheduled, PostState::Publishing)->count(),
                'failed' => Post::query()->inState(PostState::Failed)->count(),
            ],
            'history' => null,
            'can' => ['manage' => $mayChange],
            'drafting' => fn (): array => [
                'can_propose' => $request->user()?->can('create', DraftRun::class) ?? false,
                'proposal' => $this->proposals->progress(),
            ],
        ]);
    }

    /**
     * @return Builder<Post>
     */
    private function posts(PostState ...$states): Builder
    {
        return Post::query()->with(['variants.channel', 'variants.attachments.media', 'approver', 'draftRun.pillar', 'draftRun.idea'])->inState(...$states);
    }
}
