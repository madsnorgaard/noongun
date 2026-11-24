<?php

namespace App\Http\Controllers\Posts;

use App\Domain\Content\Actions\DeletePost;
use App\Domain\Content\Actions\SaveDraft;
use App\Domain\Content\Exceptions\PostNotDeletable;
use App\Domain\Content\Exceptions\PostNotEditable;
use App\Domain\Content\Models\Post;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Posts\Concerns\ActsOnPosts;
use App\Http\Presenters\PostPresenter;
use App\Http\Requests\Posts\SavePostRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The editor for a single post. Writing happens through SaveDraft and
 * DeletePost; the state of a post changes in PostTransitionController.
 */
class PostController extends Controller
{
    use ActsOnPosts;

    /** The time of day a new post is planned for when it is started from a day in the calendar. */
    private const DEFAULT_TIME = '09:00';

    public function __construct(private readonly PostPresenter $presenter) {}

    public function create(Request $request): Response
    {
        Gate::authorize('create', Post::class);

        return Inertia::render('posts/Edit', [
            'post' => null,
            'channels' => $this->presenter->channelOptions(null),
            'planned' => $this->plannedFromQuery($request),
        ]);
    }

    public function store(SavePostRequest $request, SaveDraft $save): RedirectResponse
    {
        $post = $save->handle($this->actor($request), null, $request->plannedAt(), $request->variantInputs());

        $this->toast('success', __('The draft is saved.'));

        return to_route('posts.show', $post->id);
    }

    public function show(Request $request, int $post): Response
    {
        $post = $this->findPost($post);

        Gate::authorize('view', $post);

        return Inertia::render('posts/Edit', [
            'post' => $this->presenter->present($post, $this->mayChangePosts($request)),
            'channels' => $this->presenter->channelOptions($post),
            'planned' => null,
        ]);
    }

    public function update(SavePostRequest $request, int $post, SaveDraft $save): RedirectResponse
    {
        $post = $this->findPost($post);

        Gate::authorize('update', $post);

        try {
            $save->handle($this->actor($request), $post, $request->plannedAt(), $request->variantInputs());

            $this->toast('success', __('The draft is saved.'));
        } catch (PostNotEditable) {
            $this->toast('error', __('The changes were not saved: the post is no longer a draft. Return it to draft to edit it.'));
        }

        return to_route('posts.show', $post->id);
    }

    public function destroy(Request $request, int $post, DeletePost $delete): RedirectResponse
    {
        $post = $this->findPost($post);

        Gate::authorize('delete', $post);

        try {
            $delete->handle($this->actor($request), $post);
        } catch (PostNotDeletable) {
            $this->toast('error', __('Only drafts and rejected posts that were never published can be deleted.'));

            return to_route('posts.show', $post->id);
        }

        $this->toast('success', __('The post is deleted.'));

        return to_route('queue.index');
    }

    /**
     * The calendar links to a new post on a given day: ?date=2026-11-25.
     */
    private function plannedFromQuery(Request $request): ?string
    {
        $date = $request->query('date');

        if (! is_string($date) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1) {
            return null;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            ? $date.'T'.self::DEFAULT_TIME
            : null;
    }
}
