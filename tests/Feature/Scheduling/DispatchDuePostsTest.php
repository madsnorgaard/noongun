<?php

use App\Domain\Channels\Contracts\PublishResult;
use App\Domain\Content\Actions\ApprovePost;
use App\Domain\Content\Actions\SchedulePost;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostState;
use App\Domain\Scheduling\PublishPostJob;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/publishing.php';

beforeEach(function () {
    $this->travelTo('2026-11-24 10:00:00');

    $this->fake = fakeChannelAdapter();
    $this->workspace = Workspace::factory()->create();
});

/**
 * The scheduled event for a command, or null.
 */
function scheduledCommand(string $name): ?ScheduledEvent
{
    return collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event) => str_contains((string) $event->command, $name));
}

it('claims each due post and queues one job for it', function () {
    Queue::fake();
    $due = postInState($this->workspace, PostState::Scheduled, 2, now()->subMinute());
    $exactlyNow = postInState($this->workspace, PostState::Scheduled, 1, now());
    $later = postInState($this->workspace, PostState::Scheduled, 1, now()->addSecond());

    $this->artisan('noongun:dispatch-due-posts')
        ->expectsOutputToContain('Queued 2 due post(s).')
        ->assertSuccessful();

    expect(freshPost($due)->state)->toBe(PostState::Publishing)
        ->and(freshPost($exactlyNow)->state)->toBe(PostState::Publishing)
        ->and(freshPost($later)->state)->toBe(PostState::Scheduled);

    Queue::assertPushed(PublishPostJob::class, 2);
    Queue::assertPushed(
        PublishPostJob::class,
        fn (PublishPostJob $job) => $job->postId === $due->id
            && $job->workspaceId === $this->workspace->id
            && $job->attempt === 1
            && $job->round === freshPost($due)->publish_round
            && $job->round === 1
            && $job->step === 1
            && $job->timeout === 75,
    );

    $this->fake->assertNothingPublished();
});

it('claims a post once when it runs twice in the same minute', function () {
    Queue::fake();
    $post = postInState($this->workspace, PostState::Scheduled, 1, now()->subMinute());

    $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();
    $this->artisan('noongun:dispatch-due-posts')->expectsOutputToContain('Queued 0 due post(s).')->assertSuccessful();

    Queue::assertPushed(PublishPostJob::class, 1);
    expect(freshPost($post)->state)->toBe(PostState::Publishing);
});

it('makes one platform call per variant when it runs twice in the same minute', function () {
    $post = postInState($this->workspace, PostState::Scheduled, 2, now()->subMinute());

    $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();
    $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();

    [$first, $second] = variantsOf($post);

    $this->fake->assertPublishedOnce($first)->assertPublishedOnce($second);
    expect(freshPost($post)->state)->toBe(PostState::Published);
});

it('does not queue a post that another process claimed between the query and the claim', function () {
    Queue::fake();
    $post = postInState($this->workspace, PostState::Scheduled, 1, now()->subMinute());

    // The other scheduler wins the claim just as this one has read the post.
    $won = false;
    Post::retrieved(function (Post $retrieved) use (&$won): void {
        if (! $won) {
            $won = true;
            DB::table('posts')->where('id', $retrieved->id)->update(['state' => PostState::Publishing->value]);
        }
    });

    $this->artisan('noongun:dispatch-due-posts')->expectsOutputToContain('Queued 0 due post(s).')->assertSuccessful();

    expect($won)->toBeTrue();
    Queue::assertNothingPushed();
});

it('does not send a post that a human moved to a later time between the query and the claim', function () {
    Queue::fake();
    $post = postInState($this->workspace, PostState::Scheduled, 1, now()->subMinute());

    // The command has read the post as due. Before it claims, the user
    // moves the post to next week. It stays scheduled, so only the time in
    // the claim itself can stop it.
    $moved = false;
    Post::retrieved(function (Post $retrieved) use (&$moved): void {
        if (! $moved) {
            $moved = true;
            DB::table('posts')->where('id', $retrieved->id)->update(['scheduled_at' => now()->addWeek()]);
        }
    });

    $this->artisan('noongun:dispatch-due-posts')->expectsOutputToContain('Queued 0 due post(s).')->assertSuccessful();

    expect($moved)->toBeTrue()
        ->and(freshPost($post)->state)->toBe(PostState::Scheduled)
        ->and(freshPost($post)->scheduled_at->toDateTimeString())->toBe('2026-12-01 10:00:00')
        ->and(freshPost($post)->publish_round)->toBe(0);

    Queue::assertNothingPushed();
    $this->fake->assertNothingPublished();
});

it('ignores posts that are not scheduled, even when their time has passed', function (PostState $state) {
    Queue::fake();
    $post = postInState($this->workspace, $state);
    DB::table('posts')->where('id', $post->id)->update(['scheduled_at' => now()->subHour()]);
    $before = DB::table('posts')->where('id', $post->id)->first();

    $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();

    Queue::assertNothingPushed();
    expect(DB::table('posts')->where('id', $post->id)->first())->toEqual($before);
})->with([
    PostState::Draft, PostState::Approved, PostState::Publishing,
    PostState::Published, PostState::Failed, PostState::Rejected,
]);

describe('workspaces', function () {
    it('dispatches the due posts of every workspace inside that workspace', function () {
        $other = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
        $mine = postInState($this->workspace, PostState::Scheduled, 1, now()->subMinute());
        $theirs = postInState($other, PostState::Scheduled, 1, now()->subMinute());
        $seen = [];

        $this->fake->publishReturns(function (PostVariant $variant, int $call) use (&$seen): PublishResult {
            $seen[$variant->workspace_id] = app(CurrentWorkspace::class)->id();

            return new PublishResult("fb_{$variant->id}_{$call}", null, now());
        });

        $this->artisan('noongun:dispatch-due-posts')->expectsOutputToContain('Queued 2 due post(s).')->assertSuccessful();

        expect($seen)->toBe([$this->workspace->id => $this->workspace->id, $other->id => $other->id])
            ->and(freshPost($mine)->state)->toBe(PostState::Published)
            ->and(freshPost($theirs)->state)->toBe(PostState::Published)
            ->and(freshPost($mine)->workspace_id)->toBe($this->workspace->id)
            ->and(freshPost($theirs)->workspace_id)->toBe($other->id);
    });

    it('queues each job for the workspace that owns the post', function () {
        Queue::fake();
        $other = Workspace::factory()->create();
        $mine = postInState($this->workspace, PostState::Scheduled, 1, now()->subMinute());
        $theirs = postInState($other, PostState::Scheduled, 1, now()->subMinute());

        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();

        Queue::assertPushed(PublishPostJob::class, fn (PublishPostJob $job) => $job->postId === $mine->id && $job->workspaceId === $this->workspace->id);
        Queue::assertPushed(PublishPostJob::class, fn (PublishPostJob $job) => $job->postId === $theirs->id && $job->workspaceId === $other->id);
    });

    it('leaves no current workspace behind, and restores the one that was set', function () {
        Queue::fake();
        postInState($this->workspace, PostState::Scheduled, 1, now()->subMinute());

        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();

        expect(app(CurrentWorkspace::class)->get())->toBeNull();

        $outer = Workspace::factory()->create();

        inWorkspace($outer, function () use ($outer) {
            $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();

            expect(app(CurrentWorkspace::class)->id())->toBe($outer->id);
        });
    });
});

describe('the scheduler', function () {
    it('runs the dispatch command every minute without overlapping itself', function () {
        $event = scheduledCommand('noongun:dispatch-due-posts');

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('* * * * *')
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->expiresAt)->toBe(5);
    });

    it('runs the recovery command every five minutes without overlapping itself', function () {
        $event = scheduledCommand('noongun:recover-stuck-posts');

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('*/5 * * * *')
            ->and($event->withoutOverlapping)->toBeTrue();
    });
});

describe('the right instant in each timezone', function () {
    /**
     * A post written in the workspace, approved and scheduled by an editor
     * for a wall-clock time in that workspace.
     */
    function scheduleLocally(Workspace $workspace, string $localTime): Post
    {
        $editor = userWithRole($workspace, Role::Editor);
        $post = postInState($workspace, PostState::Draft);

        return inWorkspace($workspace, function () use ($workspace, $editor, $post, $localTime): Post {
            app(ApprovePost::class)->handle($editor, $post);

            return app(SchedulePost::class)->handle($editor, $post, $workspace->toUtc($localTime));
        });
    }

    it('publishes a Copenhagen post at 09:00 local time on both sides of the change to summer time', function () {
        $this->travelTo('2027-03-20 12:00:00');
        $copenhagen = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);

        // Summer time starts on Sunday 28 March 2027 at 02:00 local time.
        $saturday = scheduleLocally($copenhagen, '2027-03-27 09:00');
        $sunday = scheduleLocally($copenhagen, '2027-03-28 09:00');

        expect(DB::table('posts')->where('id', $saturday->id)->value('scheduled_at'))->toBe('2027-03-27 08:00:00')
            ->and(DB::table('posts')->where('id', $sunday->id)->value('scheduled_at'))->toBe('2027-03-28 07:00:00');

        $this->travelTo('2027-03-27 07:59:59');
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();
        expect(freshPost($saturday)->state)->toBe(PostState::Scheduled);

        $this->travelTo('2027-03-27 08:00:00');
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();
        expect(freshPost($saturday)->state)->toBe(PostState::Published)
            ->and(freshPost($sunday)->state)->toBe(PostState::Scheduled);

        $this->travelTo('2027-03-28 06:59:59');
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();
        expect(freshPost($sunday)->state)->toBe(PostState::Scheduled);

        $this->travelTo('2027-03-28 07:00:00');
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();
        expect(freshPost($sunday)->state)->toBe(PostState::Published);

        $publishedAt = variantsOf($sunday)[0]->published_at;

        expect($copenhagen->toLocal($publishedAt)->format('Y-m-d H:i T'))->toBe('2027-03-28 09:00 CEST')
            ->and($copenhagen->toLocal(variantsOf($saturday)[0]->published_at)->format('Y-m-d H:i T'))->toBe('2027-03-27 09:00 CET');
    });

    it('publishes a Copenhagen post at 09:00 local time on both sides of the change back to winter time', function () {
        $this->travelTo('2027-10-25 12:00:00');
        $copenhagen = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);

        // Winter time starts on Sunday 31 October 2027 at 03:00 local time.
        $saturday = scheduleLocally($copenhagen, '2027-10-30 09:00');
        $sunday = scheduleLocally($copenhagen, '2027-10-31 09:00');

        $this->travelTo('2027-10-30 06:59:59');
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();
        expect(freshPost($saturday)->state)->toBe(PostState::Scheduled);

        $this->travelTo('2027-10-30 07:00:00');
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();
        expect(freshPost($saturday)->state)->toBe(PostState::Published);

        $this->travelTo('2027-10-31 07:59:59');
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();
        expect(freshPost($sunday)->state)->toBe(PostState::Scheduled);

        $this->travelTo('2027-10-31 08:00:00');
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();
        expect(freshPost($sunday)->state)->toBe(PostState::Published);
    });

    it('publishes a Johannesburg post at 09:00 local time, which is 07:00 UTC all year', function (string $start, string $localTime, string $early, string $onTime) {
        $this->travelTo($start);
        $johannesburg = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
        $post = scheduleLocally($johannesburg, $localTime);

        $this->travelTo($early);
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();
        expect(freshPost($post)->state)->toBe(PostState::Scheduled);

        $this->travelTo($onTime);
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();
        expect(freshPost($post)->state)->toBe(PostState::Published)
            ->and($johannesburg->toLocal(variantsOf($post)[0]->published_at)->format('H:i T'))->toBe('09:00 SAST');
    })->with([
        'in the southern summer' => ['2027-01-10 12:00:00', '2027-01-15 09:00', '2027-01-15 06:59:59', '2027-01-15 07:00:00'],
        'in the southern winter' => ['2027-07-10 12:00:00', '2027-07-15 09:00', '2027-07-15 06:59:59', '2027-07-15 07:00:00'],
        'on the day Copenhagen changes' => ['2027-03-20 12:00:00', '2027-03-28 09:00', '2027-03-28 06:59:59', '2027-03-28 07:00:00'],
    ]);

    it('sends two workspaces their 09:00 posts at different instants on the same day', function () {
        $this->travelTo('2027-01-10 12:00:00');
        $copenhagen = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
        $johannesburg = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
        $north = scheduleLocally($copenhagen, '2027-01-15 09:00');
        $south = scheduleLocally($johannesburg, '2027-01-15 09:00');

        $this->travelTo('2027-01-15 07:00:00');
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();

        expect(freshPost($south)->state)->toBe(PostState::Published)
            ->and(freshPost($north)->state)->toBe(PostState::Scheduled);

        $this->travelTo('2027-01-15 08:00:00');
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();

        expect(freshPost($north)->state)->toBe(PostState::Published);
    });
});
