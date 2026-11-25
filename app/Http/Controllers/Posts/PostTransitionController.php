<?php

namespace App\Http\Controllers\Posts;

use App\Domain\Content\Actions\ApprovePost;
use App\Domain\Content\Actions\RejectPost;
use App\Domain\Content\Actions\RetryPost;
use App\Domain\Content\Actions\ReturnPostToDraft;
use App\Domain\Content\Actions\SchedulePost;
use App\Domain\Content\Actions\UnschedulePost;
use App\Domain\Content\Exceptions\IllegalPostTransition;
use App\Domain\Content\Exceptions\PostNotPublishable;
use App\Domain\Content\Models\Post;
use App\Domain\Content\PostState;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Posts\Concerns\ActsOnPosts;
use App\Http\Presenters\PostPresenter;
use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Moves a post through its states, one request of a signed-in user per
 * move. Each method calls one action class; nothing here talks to a
 * platform.
 *
 * Every move runs in a transaction that first locks the post row. SaveDraft
 * takes the same lock, so the texts cannot change between the readiness
 * check and the approval, and an edit can never land on a post that was
 * approved a moment earlier.
 *
 * Approving needs the fingerprint of the content the user was shown. If the
 * post was edited since, the approval is refused and the user reads it
 * again. There is no approval without a request that names the post.
 */
class PostTransitionController extends Controller
{
    use ActsOnPosts;

    /** The most posts one request from the queue may approve. */
    private const MAX_SELECTION = 100;

    public function __construct(
        private readonly PostPresenter $presenter,
        private readonly CurrentWorkspace $currentWorkspace,
    ) {}

    public function approve(Request $request, int $post, ApprovePost $action): RedirectResponse
    {
        Gate::authorize('create', Post::class);

        $version = $this->seenVersion($request);

        return $this->transition($request, $post, 'approve', function (User $actor, Post $post) use ($action, $version): string {
            $this->assertSeen($post, $version);

            return $action->handle($actor, $post)->state === PostState::Scheduled
                ? __('The post is approved and scheduled.')
                : __('The post is approved. It has no time yet.');
        });
    }

    /**
     * Approves the drafts the user ticked in the queue. Each one is named in
     * the request with the fingerprint of what was shown, and each one is
     * approved, or refused, by itself.
     */
    public function approveSelected(Request $request, ApprovePost $action): RedirectResponse
    {
        Gate::authorize('create', Post::class);

        $validated = $request->validate([
            'posts' => ['required', 'array', 'min:1', 'max:'.self::MAX_SELECTION],
            'posts.*.id' => ['required', 'integer', 'distinct'],
            'posts.*.version' => ['required', 'string'],
        ], [
            'posts.required' => __('Select at least one draft.'),
            'posts.min' => __('Select at least one draft.'),
        ]);

        $selection = [];

        foreach ($validated['posts'] as $selected) {
            $selection[(int) $selected['id']] = (string) $selected['version'];
        }

        abort_unless(Post::query()->whereKey(array_keys($selection))->count() === count($selection), 404);

        $actor = $this->actor($request);
        $approved = 0;
        $refused = [];

        foreach ($selection as $id => $version) {
            try {
                DB::transaction(function () use ($action, $actor, $id, $version): void {
                    $post = Post::query()->lockForUpdate()->findOrFail($id);

                    Gate::authorize('approve', $post);
                    $this->assertSeen($post, $version);

                    $action->handle($actor, $post);
                });

                $approved++;
            } catch (PostNotPublishable $exception) {
                $refused["posts.{$id}"] = $exception->getMessage();
            } catch (ValidationException $exception) {
                $refused["posts.{$id}"] = $exception->getMessage();
            } catch (IllegalPostTransition) {
                $refused["posts.{$id}"] = $this->notPossibleNow($id);
            }
        }

        if ($refused === []) {
            $this->toast('success', trans_choice('{1} :count post is approved.|[2,*] :count posts are approved.', $approved, ['count' => $approved]));

            return back();
        }

        $this->toast('warning', __(':approved approved, :refused not approved. Each one that is left says why.', ['approved' => $approved, 'refused' => count($refused)]));

        // One message per refused post, keyed "posts.{id}", so the queue can
        // show each reason at its draft.
        return back()->withErrors($refused);
    }

    public function reject(Request $request, int $post, RejectPost $action): RedirectResponse
    {
        return $this->transition($request, $post, 'reject', function (User $actor, Post $post) use ($action): string {
            $action->handle($actor, $post);

            return __('The post is rejected.');
        });
    }

    /**
     * Commits an approved post to a time, or moves a scheduled post. The
     * time arrives as wall-clock time in the workspace timezone. With "now"
     * the post goes out at the next run of the scheduler.
     */
    public function schedule(Request $request, int $post, SchedulePost $action): RedirectResponse
    {
        Gate::authorize('create', Post::class);

        $now = $request->boolean('now');

        $validated = $request->validate([
            'scheduled_at' => [$now ? 'nullable' : 'required', 'date_format:'.PostPresenter::LOCAL_FORMAT],
        ], [
            'scheduled_at.required' => __('Choose a date and a time.'),
            'scheduled_at.date_format' => __('The time is not a valid date and time.'),
        ]);

        $at = $now ? now() : $this->currentWorkspace->getOrFail()->toUtc((string) $validated['scheduled_at']);

        return $this->transition($request, $post, 'schedule', function (User $actor, Post $post) use ($action, $at): string {
            $action->handle($actor, $post, $at);

            return __('The post is scheduled.');
        });
    }

    public function unschedule(Request $request, int $post, UnschedulePost $action): RedirectResponse
    {
        return $this->transition($request, $post, 'unschedule', function (User $actor, Post $post) use ($action): string {
            $action->handle($actor, $post);

            return __('The post is taken off the schedule. It stays approved.');
        });
    }

    public function returnToDraft(Request $request, int $post, ReturnPostToDraft $action): RedirectResponse
    {
        return $this->transition($request, $post, 'returnToDraft', function (User $actor, Post $post) use ($action): string {
            $hadApproval = $post->approved_at !== null;

            $action->handle($actor, $post);

            return $hadApproval
                ? __('The post is a draft again. Its approval is removed.')
                : __('The post is a draft again.');
        });
    }

    public function retry(Request $request, int $post, RetryPost $action): RedirectResponse
    {
        return $this->transition($request, $post, 'retry', function (User $actor, Post $post) use ($action): string {
            $action->handle($actor, $post);

            return __('The post is in the queue to be sent again.');
        });
    }

    /**
     * Locks the post, checks the ability and runs the change. The closure
     * returns the message for the user.
     *
     * @param  Closure(User, Post): string  $change
     */
    private function transition(Request $request, int $id, string $ability, Closure $change): RedirectResponse
    {
        $actor = $this->actor($request);

        try {
            $message = DB::transaction(function () use ($actor, $id, $ability, $change): string {
                $post = Post::query()->lockForUpdate()->findOrFail($id);

                Gate::authorize($ability, $post);

                return $change($actor, $post);
            });
        } catch (PostNotPublishable $exception) {
            throw ValidationException::withMessages($exception->errors());
        } catch (IllegalPostTransition) {
            $this->toast('error', $this->notPossibleNow($id));

            return back();
        }

        $this->toast('success', $message);

        return back();
    }

    private function seenVersion(Request $request): string
    {
        $validated = $request->validate(['version' => ['required', 'string', 'max:64']]);

        return (string) $validated['version'];
    }

    /**
     * @throws ValidationException when the content is not what the user was shown
     */
    private function assertSeen(Post $post, string $version): void
    {
        if (! hash_equals($this->presenter->version($post), $version)) {
            throw ValidationException::withMessages([
                'version' => [__('The post was changed after you opened it. Read it again before you approve it.')],
            ]);
        }
    }

    private function notPossibleNow(int $id): string
    {
        return match (Post::query()->find($id)?->state) {
            null => __('That is not possible: the post no longer exists.'),
            PostState::Draft => __('That is not possible: the post is a draft now.'),
            PostState::Approved => __('That is not possible: the post is approved now.'),
            PostState::Scheduled => __('That is not possible: the post is scheduled now.'),
            PostState::Publishing => __('That is not possible: the post is being published now.'),
            PostState::Published => __('That is not possible: the post is published now.'),
            PostState::Failed => __('That is not possible: the post has failed.'),
            PostState::Rejected => __('That is not possible: the post is rejected now.'),
        };
    }
}
