<?php

/*
 * Project rule 1: a post reaches a platform only from an approved state, and
 * only a human action sets that state.
 *
 * These tests are the tripwire for that rule. If one of them fails because
 * of a change you made, the change is wrong, not the test.
 */

use App\Domain\Content\Actions\ApprovePost;
use App\Domain\Content\Models\Post;
use App\Domain\Content\PostState;
use App\Domain\Scheduling\PublishPostJob;
use App\Domain\Scheduling\SettleInterruptedPost;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;

require_once __DIR__.'/../../Support/publishing.php';

beforeEach(function () {
    $this->travelTo('2026-11-24 10:00:00');

    $this->fake = fakeChannelAdapter();
    $this->workspace = Workspace::factory()->create();
});

/**
 * Every PHP file of the application with its source, keyed by path relative
 * to app/.
 *
 * @return array<string, string>
 */
function applicationSources(): array
{
    $sources = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $sources[str_replace('\\', '/', $file->getRelativePathname())] = $file->getContents();
    }

    return $sources;
}

/**
 * The files whose source matches the pattern.
 *
 * @return list<string>
 */
function sourcesMatching(string $pattern, ?string $under = null): array
{
    return array_keys(array_filter(
        applicationSources(),
        fn (string $source, string $path) => ($under === null || str_starts_with($path, $under)) && preg_match($pattern, $source) === 1,
        ARRAY_FILTER_USE_BOTH,
    ));
}

/**
 * Runs everything that works without a human: the scheduler commands, a
 * publishing job for the post in every shape it can be queued, the job's
 * failure hook and the recovery.
 */
function runEverythingAutomatic(Post $post): void
{
    $current = freshPost($post)->publish_round;

    foreach ([$current, $current + 1] as $round) {
        foreach ([1, 2, 5] as $step) {
            $job = new PublishPostJob($post->workspace_id, $post->id, $round, step: $step, attempt: $step);

            dispatch(clone $job);
            runPublishJob(clone $job);
            $job->failed(new RuntimeException('Failed.'));
        }
    }

    test()->artisan('noongun:dispatch-due-posts')->assertSuccessful();
    test()->travel(3)->days();
    test()->artisan('noongun:recover-stuck-posts')->assertSuccessful();
    test()->artisan('noongun:dispatch-due-posts')->assertSuccessful();

    inWorkspace(test()->workspace, fn () => app(SettleInterruptedPost::class)->handle($post));
}

describe('posts that were never approved', function () {
    it('are never sent, whatever runs automatically', function (PostState $state) {
        $post = postInState($this->workspace, $state, 2);
        DB::table('posts')->where('id', $post->id)->update(['scheduled_at' => now()->subHour()]);
        $post->refresh();
        $before = DB::table('posts')->where('id', $post->id)->first();

        runEverythingAutomatic($post);

        $this->fake->assertNothingPublished();
        expect($this->fake->calls)->toBe([])
            ->and(DB::table('posts')->where('id', $post->id)->first())->toEqual($before)
            ->and(freshPost($post)->approved_at)->toBeNull()
            ->and(variantsOf($post)[0]->isPublished())->toBeFalse();
    })->with([PostState::Draft, PostState::Rejected]);

    it('are not sent after an approval was taken back', function () {
        $editor = userWithRole($this->workspace, Role::Editor);
        $post = postInState($this->workspace, PostState::Scheduled, 1, now()->subMinute());

        // Queued for a claim that never happened, then returned to draft.
        $job = PublishPostJob::forClaimed($post);
        inWorkspace($this->workspace, fn () => $post->returnToDraft());

        runPublishJob($job);
        runEverythingAutomatic($post);

        $this->fake->assertNothingPublished();
        expect(freshPost($post)->state)->toBe(PostState::Draft);
    });
});

describe('approved posts that nobody scheduled', function () {
    it('are never sent or scheduled automatically', function () {
        $post = postInState($this->workspace, PostState::Approved, 1);
        DB::table('posts')->where('id', $post->id)->update(['scheduled_at' => now()->subHour()]);
        $post->refresh();

        runEverythingAutomatic($post);

        $this->fake->assertNothingPublished();
        expect(freshPost($post)->state)->toBe(PostState::Approved);
    });
});

describe('the publishing job', function () {
    it('sends nothing unless the scheduler claimed the post', function (PostState $state) {
        $post = postInState($this->workspace, $state, 2);
        $before = DB::table('posts')->where('id', $post->id)->first();

        $job = new PublishPostJob($post->workspace_id, $post->id, freshPost($post)->publish_round);
        runPublishJob($job);
        dispatch(clone $job);

        expect($this->fake->calls)->toBe([])
            ->and(DB::table('posts')->where('id', $post->id)->first())->toEqual($before);
    })->with([
        PostState::Draft, PostState::Approved, PostState::Scheduled,
        PostState::Published, PostState::Failed, PostState::Rejected,
    ]);

    it('cannot find a publishing post without an approval, because the database refuses the row', function () {
        $post = postInState($this->workspace, PostState::Draft);

        expect(fn () => DB::table('posts')->where('id', $post->id)->update(['state' => 'publishing', 'scheduled_at' => now()]))
            ->toThrow(QueryException::class);
    });
});

describe('failed posts', function () {
    it('are never sent again without a human retry', function () {
        $post = postInState($this->workspace, PostState::Failed, 2);

        runEverythingAutomatic($post);

        $this->fake->assertNothingPublished();
        expect(freshPost($post)->state)->toBe(PostState::Failed);
    });

    it('stay failed after the recovery: it never puts a post back in the queue', function () {
        [$post] = claimedPost($this->workspace, 1);

        $this->travel(3)->days();
        $this->artisan('noongun:recover-stuck-posts')->assertSuccessful();
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();

        $this->fake->assertNothingPublished();
        expect(freshPost($post)->state)->toBe(PostState::Failed);
    });
});

describe('the code', function () {
    it('calls Post::approve() in one place only: the ApprovePost action', function () {
        expect(sourcesMatching('/->approve\(/'))->toBe(['Domain/Content/Actions/ApprovePost.php']);
    });

    it('uses ApprovePost only from the HTTP layer, where a signed-in human is acting', function () {
        $users = array_values(array_filter(
            sourcesMatching('/\bApprovePost\b/'),
            fn (string $path) => $path !== 'Domain/Content/Actions/ApprovePost.php' && ! str_starts_with($path, 'Http/'),
        ));

        expect($users)->toBe([]);
    });

    it('writes the state and the approval of a post only in the Post model', function () {
        $pattern = '/[\'"](approved_at|approved_by)[\'"]\s*=>|[\'"]state[\'"]\s*=>\s*PostState::|PostState::Approved->value/';

        expect(sourcesMatching($pattern))->toBe(['Domain/Content/Models/Post.php']);
    });

    it('lets the scheduler, the job and the recovery move a post only by claiming and finishing it', function () {
        $forbidden = '/->(approve|schedule|unschedule|retry|returnToDraft|reject)\(|\b(ApprovePost|SchedulePost|RetryPost)\b|forceFill|DB::(table|statement|update|insert)|withoutGlobalScope/';

        expect(sourcesMatching($forbidden, 'Domain/Scheduling/'))->toBe([]);
    });

    it('has no job or command that reaches a channel adapter except PublishPostJob', function () {
        $callers = array_values(array_filter(
            sourcesMatching('/->publish\(/'),
            fn (string $path) => ! str_starts_with($path, 'Domain/Channels/'),
        ));

        expect($callers)->toBe(['Domain/Scheduling/PublishPostJob.php']);
    });

    it('does not let a queued job or a console command resolve ApprovePost', function () {
        foreach (applicationSources() as $path => $source) {
            if (preg_match('/implements\s+[^{]*ShouldQueue|extends\s+Command\b/', $source) === 1) {
                expect($source)->not->toContain('ApprovePost', "{$path} must not approve posts.");
            }
        }

        expect(class_exists(ApprovePost::class))->toBeTrue();
    });
});
