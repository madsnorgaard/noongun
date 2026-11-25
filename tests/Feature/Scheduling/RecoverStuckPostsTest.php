<?php

use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Actions\RetryPost;
use App\Domain\Content\PostState;
use App\Domain\Scheduling\Events\PostFailedToPublish;
use App\Domain\Scheduling\PublishingConfig;
use App\Domain\Scheduling\SettleInterruptedPost;
use App\Domain\Scheduling\VariantLock;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/publishing.php';

beforeEach(function () {
    $this->travelTo('2026-11-24 10:00:00');

    config([
        'noongun.publishing.max_attempts' => 5,
        'noongun.publishing.backoff' => [60, 600],
        'noongun.publishing.stuck_after_minutes' => 30,
    ]);

    $this->fake = fakeChannelAdapter();
    $this->workspace = Workspace::factory()->create();
});

it('treats a post as stuck after the configured minutes plus the longest backoff', function () {
    expect(PublishingConfig::stuckAfterSeconds())->toBe(30 * 60 + 600);
});

it('fails a post whose worker died, without calling the platform', function () {
    Event::fake([PostFailedToPublish::class]);
    [$post] = claimedPost($this->workspace, 2);

    $this->travel(40)->minutes();
    $this->artisan('noongun:recover-stuck-posts')
        ->expectsOutputToContain('Settled 1 interrupted post(s).')
        ->assertSuccessful();

    [$first, $second] = variantsOf($post);

    $this->fake->assertNothingPublished();
    expect(freshPost($post)->state)->toBe(PostState::Failed)
        ->and(freshPost($post)->approved_at)->not->toBeNull()
        ->and($first->error)->toBe(SettleInterruptedPost::NOT_SENT)
        ->and($second->error)->toBe(SettleInterruptedPost::NOT_SENT)
        ->and($first->isPublished())->toBeFalse();

    Event::assertDispatched(PostFailedToPublish::class, fn (PostFailedToPublish $event) => $event->postId === $post->id);
});

it('leaves a post alone until it has been silent for long enough', function () {
    [$post] = claimedPost($this->workspace, 1);

    $this->travel(40)->minutes();
    $this->travel(-1)->seconds();
    $this->artisan('noongun:recover-stuck-posts')->expectsOutputToContain('Settled 0 interrupted post(s).')->assertSuccessful();

    expect(freshPost($post)->state)->toBe(PostState::Publishing)
        ->and(variantsOf($post)[0]->error)->toBeNull();

    $this->travel(1)->seconds();
    $this->artisan('noongun:recover-stuck-posts')->assertSuccessful();

    expect(freshPost($post)->state)->toBe(PostState::Failed);
});

it('does not cut off a post that is waiting for its next attempt', function () {
    Queue::fake();
    [$post, $job] = claimedPost($this->workspace, 1);
    $this->fake->failRetryable(times: 2);

    // First attempt at 10:35, just before the post would count as stuck.
    $this->travel(35)->minutes();
    runPublishJob($job);
    $second = Queue::pushed($job::class)->sole();

    // The second attempt is due ten minutes later and is itself late.
    $this->travel(35)->minutes();
    $this->artisan('noongun:recover-stuck-posts')->assertSuccessful();
    expect(freshPost($post)->state)->toBe(PostState::Publishing);

    runPublishJob($second);
    runPublishJob(Queue::pushed($job::class)->last());

    expect(freshPost($post)->state)->toBe(PostState::Published);
});

it('finishes a post as published when every variant is already on its platform', function () {
    Event::fake([PostFailedToPublish::class]);
    [$post] = claimedPost($this->workspace, 2);

    foreach (variantsOf($post) as $variant) {
        DB::table('post_variants')->where('id', $variant->id)->update(['external_id' => "fb_{$variant->id}", 'published_at' => now()]);
    }

    $this->travel(41)->minutes();
    $this->artisan('noongun:recover-stuck-posts')->assertSuccessful();

    $this->fake->assertNothingPublished();
    expect(freshPost($post)->state)->toBe(PostState::Published);
    Event::assertNotDispatched(PostFailedToPublish::class);
});

it('never causes a second platform call for a variant that has an external id', function () {
    $channels = inWorkspace($this->workspace, fn () => Channel::factory()->count(2)->create()->all());
    [$post] = claimedPost($this->workspace, $channels);
    [$out, $missing] = variantsOf($post);

    // The worker published the first variant and died before the second.
    DB::table('post_variants')->where('id', $out->id)->update(['external_id' => 'fb_out', 'permalink' => 'https://platform.test/posts/fb_out', 'published_at' => now()]);

    $this->travel(41)->minutes();
    $this->artisan('noongun:recover-stuck-posts')->assertSuccessful();

    [$out, $missing] = variantsOf($post);

    expect(freshPost($post)->state)->toBe(PostState::Failed)
        ->and($out->external_id)->toBe('fb_out')
        ->and($out->permalink)->toBe('https://platform.test/posts/fb_out')
        ->and($out->error)->toBeNull()
        ->and($missing->error)->toBe(SettleInterruptedPost::NOT_SENT);

    $this->fake->assertNothingPublished();

    // A human retries: only the missing variant is sent.
    $editor = userWithRole($this->workspace, Role::Editor);
    inWorkspace($this->workspace, fn () => app(RetryPost::class)->handle($editor, $post));
    $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();

    $this->fake->assertNotPublished($out)->assertPublishedOnce($missing);
    expect(freshPost($post)->state)->toBe(PostState::Published)
        ->and(variantsOf($post)[0]->external_id)->toBe('fb_out');
});

it('makes the late job harmless once the post is settled', function () {
    [$post, $job] = claimedPost($this->workspace, 1);

    $this->travel(41)->minutes();
    $this->artisan('noongun:recover-stuck-posts')->assertSuccessful();

    // The queue comes back and delivers the job that was waiting.
    runPublishJob($job);

    $this->fake->assertNothingPublished();
    expect(freshPost($post)->state)->toBe(PostState::Failed);
});

it('leaves a post alone while a job holds one of its variants', function () {
    [$post] = claimedPost($this->workspace, 2);
    $held = VariantLock::for(variantsOf($post)[1]->id, 86400);
    expect($held->get())->toBeTrue();

    $this->travel(41)->minutes();
    $this->artisan('noongun:recover-stuck-posts')->expectsOutputToContain('Settled 0 interrupted post(s).')->assertSuccessful();

    expect(freshPost($post)->state)->toBe(PostState::Publishing)
        ->and(variantsOf($post)[0]->error)->toBeNull();

    // It did not keep the lock it had taken on the first variant.
    $first = VariantLock::for(variantsOf($post)[0]->id, 5);
    expect($first->get())->toBeTrue();
    $first->release();
});

it('tells the user to check the channel when a platform call was started and never answered', function () {
    [$post] = claimedPost($this->workspace, 2);
    [$inDoubt, $untouched] = variantsOf($post);

    // The worker died inside the call for the first variant.
    DB::table('post_variants')->where('id', $inDoubt->id)->update(['outcome_unknown_since' => now()]);

    $this->travel(41)->minutes();
    $this->artisan('noongun:recover-stuck-posts')->assertSuccessful();

    [$inDoubt, $untouched] = variantsOf($post);

    expect($inDoubt->error)->toBe(SettleInterruptedPost::MESSAGE)
        ->and($inDoubt->hasUnknownOutcome())->toBeTrue()
        ->and($untouched->error)->toBe(SettleInterruptedPost::NOT_SENT)
        ->and($untouched->hasUnknownOutcome())->toBeFalse();
});

it('keeps the last recorded error next to its own message', function () {
    [$post] = claimedPost($this->workspace, 1);
    DB::table('post_variants')->where('post_id', $post->id)->update(['error' => 'The platform is busy.', 'outcome_unknown_since' => now()]);

    $this->travel(41)->minutes();
    $this->artisan('noongun:recover-stuck-posts')->assertSuccessful();

    expect(variantsOf($post)[0]->error)
        ->toBe(SettleInterruptedPost::MESSAGE.' Last recorded error: The platform is busy.');
});

it('keeps the error of a variant the platform refused, because nothing about it is in doubt', function () {
    [$post] = claimedPost($this->workspace, 1);
    DB::table('post_variants')->where('post_id', $post->id)->update(['error' => 'The platform is busy.']);

    $this->travel(41)->minutes();
    $this->artisan('noongun:recover-stuck-posts')->assertSuccessful();

    expect(variantsOf($post)[0]->error)->toBe('The platform is busy.')
        ->and(freshPost($post)->state)->toBe(PostState::Failed);
});

it('only looks at posts that are publishing', function (PostState $state) {
    $post = postInState($this->workspace, $state);
    $this->travel(3)->days();
    $before = DB::table('posts')->where('id', $post->id)->first();

    $this->artisan('noongun:recover-stuck-posts')->expectsOutputToContain('Settled 0 interrupted post(s).')->assertSuccessful();

    expect(DB::table('posts')->where('id', $post->id)->first())->toEqual($before);
    $this->fake->assertNothingPublished();
})->with([
    PostState::Draft, PostState::Approved, PostState::Scheduled,
    PostState::Published, PostState::Failed, PostState::Rejected,
]);

it('settles the stuck posts of every workspace and leaves no current workspace behind', function () {
    $other = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
    [$mine] = claimedPost($this->workspace, 1);
    [$theirs] = claimedPost($other, 1);

    $this->travel(41)->minutes();
    $this->artisan('noongun:recover-stuck-posts')->expectsOutputToContain('Settled 2 interrupted post(s).')->assertSuccessful();

    expect(freshPost($mine)->state)->toBe(PostState::Failed)
        ->and(freshPost($theirs)->state)->toBe(PostState::Failed)
        ->and(app(CurrentWorkspace::class)->get())->toBeNull();
});
