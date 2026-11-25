<?php

/*
 * Project rule 1: a post reaches a platform only from an approved state, and
 * only a human action sets that state.
 *
 * Drafting writes posts without a human typing them, so it is where that
 * rule is easiest to break. These tests are the tripwire for drafting, next
 * to tests/Feature/Scheduling/NothingPublishesWithoutApprovalTest.php. If
 * one of them fails because of a change you made, the change is wrong, not
 * the test.
 */

use App\Domain\Content\Models\Post;
use App\Domain\Content\PostState;
use App\Domain\Drafting\Actions\CreateDraftFromResult;
use App\Domain\Drafting\Actions\GenerateDraft;
use App\Domain\Drafting\Exceptions\InvalidDraftOutput;
use App\Domain\Scheduling\PublishPostJob;
use App\Domain\Scheduling\SettleInterruptedPost;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeDraftGenerator;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';
require_once __DIR__.'/../../Support/sources.php';

beforeEach(function () {
    $this->travelTo('2026-11-25 10:00:00');

    $this->adapter = fakeChannelAdapter();
    $this->generator = fakeDraftGenerator();
    $this->workspace = Workspace::factory()->create();
    $this->owner = userWithRole($this->workspace, Role::Owner);
    $this->channel = channelIn($this->workspace);
});

/**
 * Generates a post for the test's channel, planned for the given time.
 */
function generatedPost(?DateTimeInterface $plannedAt = null): Post
{
    $test = test();
    $request = draftRequestIn($test->workspace, [$test->channel]);
    $run = draftRunIn($test->workspace, $test->owner);

    return inWorkspace($test->workspace, fn () => app(GenerateDraft::class)->handle($test->owner, $run, $request, $plannedAt));
}

describe('a generated post', function () {
    it('is a draft without an approval', function () {
        $post = generatedPost();

        expect(DB::table('posts')->where('id', $post->id)->first())->toMatchObject([
            'state' => 'draft',
            'approved_at' => null,
            'approved_by' => null,
            'scheduled_at' => null,
            'publish_round' => 0,
        ]);
    });

    it('is still a draft when it was given a time, also one that has passed', function (string $plannedAt) {
        $post = generatedPost(new DateTimeImmutable($plannedAt));

        expect(stateOf($post))->toBe('draft')
            ->and(freshPost($post)->approved_at)->toBeNull()
            ->and(freshPost($post)->scheduled_at?->toDateTimeString())->toBe($plannedAt);
    })->with(['2026-11-26 09:00:00', '2026-11-25 09:00:00']);

    it('is never sent, whatever runs automatically', function () {
        $post = generatedPost(now()->subHour());
        $before = DB::table('posts')->where('id', $post->id)->first();

        foreach ([0, 1] as $round) {
            foreach ([1, 2] as $step) {
                $job = new PublishPostJob($post->workspace_id, $post->id, $round, step: $step, attempt: $step);

                dispatch(clone $job);
                runPublishJob(clone $job);
                $job->failed(new RuntimeException('Failed.'));
            }
        }

        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();
        $this->travel(3)->days();
        $this->artisan('noongun:recover-stuck-posts')->assertSuccessful();
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();
        inWorkspace($this->workspace, fn () => app(SettleInterruptedPost::class)->handle($post));

        $this->adapter->assertNothingPublished();

        expect($this->adapter->calls)->toBe([])
            ->and(DB::table('posts')->where('id', $post->id)->first())->toEqual($before)
            ->and(variantsOf($post)[0]->isPublished())->toBeFalse();
    });

    it('queues no job and fires nothing that publishes', function () {
        Queue::fake();

        generatedPost(now()->addDay());

        Queue::assertNothingPushed();
    });

    it('stays a draft however many are generated', function () {
        foreach (range(1, 5) as $ignored) {
            generatedPost(now()->addDays(2));
        }

        expect(DB::table('posts')->pluck('state')->unique()->all())->toBe(['draft'])
            ->and(DB::table('posts')->whereNotNull('approved_at')->count())->toBe(0)
            ->and(DB::table('posts')->count())->toBe(5);
    });

    it('cannot be born approved, because a result has nowhere to say so', function () {
        $request = draftRequestIn($this->workspace, [$this->channel]);
        $run = draftRunIn($this->workspace, $this->owner);

        $this->generator->returnsOutput(fn ($request) => [
            ...FakeDraftGenerator::outputFor($request),
            'state' => 'approved',
            'approved' => true,
            'scheduled_at' => '2026-11-25 10:00:00',
        ]);

        inWorkspace($this->workspace, function () use ($run, $request) {
            expect(fn () => app(GenerateDraft::class)->handle($this->owner, $run, $request))
                ->toThrow(InvalidDraftOutput::class);
        });

        expect(DB::table('posts')->count())->toBe(0);
    });
});

describe('the code', function () {
    it('creates posts from drafting in one place only: CreateDraftFromResult, through SaveDraft', function () {
        expect(sourcesMatching('/^use .*\\\\SaveDraft;|\bSaveDraft \$|SaveDraft::class/m', 'Domain/Drafting/'))->toBe(['Domain/Drafting/Actions/CreateDraftFromResult.php'])
            ->and(sourcesMatching('/Post::(query|create|factory|make)\b|new Post\b|PostVariant::|->variants\(\)->/', 'Domain/Drafting/'))->toBe([])
            ->and(sourcesMatching('/->attributeTo\(/'))->toBe(['Domain/Drafting/Actions/CreateDraftFromResult.php'])
            ->and(class_exists(CreateDraftFromResult::class))->toBeTrue();
    });

    it('has nothing in drafting that can approve, schedule, retry or publish', function () {
        $forbidden = '/->(approve|schedule|unschedule|retry|claimForPublishing|beginPublishingStep|finishPublishing|publish|markPublished|beginAttempt)\('
            .'|\b(ApprovePost|SchedulePost|UnschedulePost|RetryPost|PublishPostJob|DispatchDuePosts|ChannelAdapter)\b'
            .'|PostState::(Approved|Scheduled|Publishing|Published)'
            .'|[\'"](approved_at|approved_by|state)[\'"]\s*=>/';

        expect(sourcesMatching($forbidden, 'Domain/Drafting/'))->toBe([]);
    });

    it('has nothing in drafting that writes around the models', function () {
        expect(sourcesMatching('/DB::(table|statement|update|insert|unprepared)|withoutGlobalScope|withoutEvents|unguard/', 'Domain/Drafting/'))->toBe([])
            ->and(sourcesMatching('/forceFill/', 'Domain/Drafting/'))->toBe(['Domain/Drafting/Models/DraftRun.php']);
    });

    it('records the origin of a post without touching its state', function () {
        $draft = postInState($this->workspace, PostState::Draft, [$this->channel], now()->addDay());
        $before = DB::table('posts')->where('id', $draft->id)->first();
        $run = draftRunIn($this->workspace, $this->owner, pillarIn($this->workspace));

        $this->travel(1)->minutes();
        inWorkspace($this->workspace, fn () => $draft->attributeTo($run));

        $after = DB::table('posts')->where('id', $draft->id)->first();

        expect(array_keys(array_diff_assoc((array) $after, (array) $before)))->toEqualCanonicalizing(['draft_run_id', 'pillar_id', 'updated_at'])
            ->and($after->state)->toBe('draft');
    });
});
