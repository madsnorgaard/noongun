<?php

/*
 * A post that is due after the access to one of its channels has ended would
 * fail for certain: the publishing job does not call a platform with an
 * access that has run out. A LinkedIn access lasts 60 days, so this is an
 * ordinary thing to run into. Such a time is refused where a time is fixed,
 * with the date on the clock of the workspace and what to do about it.
 */

use App\Domain\Content\Actions\ApprovePost;
use App\Domain\Content\Actions\SchedulePost;
use App\Domain\Content\Exceptions\PostNotPublishable;
use App\Domain\Content\PostState;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';

// Half past ten at night in UTC: still the 24th in Copenhagen, already the
// 25th in Johannesburg.
const ACCESS_ENDS = '2027-01-24 22:30:00';

beforeEach(function () {
    $this->travelTo('2026-11-25 10:00:00');
    Http::preventStrayRequests();

    $this->fake = fakeChannelAdapter();

    $this->setUpWorkspace = function (string $timezone): void {
        $this->workspace = Workspace::factory()->create(['timezone' => $timezone]);
        $this->owner = userWithRole($this->workspace, Role::Owner);
        $this->channel = channelIn($this->workspace, ['display_name' => 'Thandi Mokoena', 'token_expires_at' => ACCESS_ENDS]);
    };

    $this->message = fn (string $date): string => "The access to Thandi Mokoena ends on {$date}, before this post is due. Connect Thandi Mokoena again on the Channels page before then, or choose an earlier time.";
});

dataset('workspace clocks', [
    'Europe/Copenhagen' => ['Europe/Copenhagen', '24 January 2027'],
    'Africa/Johannesburg' => ['Africa/Johannesburg', '25 January 2027'],
]);

it('says in the editor and the queue that a draft is planned for after the access ends', function (string $timezone, string $date) {
    ($this->setUpWorkspace)($timezone);
    $post = postInState($this->workspace, PostState::Draft, [$this->channel], CarbonImmutable::parse('2027-02-01 09:00:00'));

    $this->actingAs($this->owner)->get(route('posts.show', $post->id))->assertInertia(fn (Assert $page) => $page
        ->where('post.problems', ['scheduled_at' => [($this->message)($date)]])
        ->where('post.can.approve', true));

    $this->get(route('queue.index'))->assertSee(($this->message)($date), false);
})->with('workspace clocks');

it('refuses to approve such a draft, and approves it once the channel is connected again', function (string $timezone, string $date) {
    ($this->setUpWorkspace)($timezone);
    $post = postInState($this->workspace, PostState::Draft, [$this->channel], CarbonImmutable::parse('2027-02-01 09:00:00'));

    try {
        inWorkspace($this->workspace, fn () => app(ApprovePost::class)->handle($this->owner, $post));
        $this->fail('Expected PostNotPublishable.');
    } catch (PostNotPublishable $exception) {
        expect($exception->errors())->toBe(['scheduled_at' => [($this->message)($date)]]);
    }

    expect(freshPost($post)->state)->toBe(PostState::Draft);

    // Connecting again gives the access a new end date.
    DB::table('channels')->where('id', $this->channel->id)->update(['token_expires_at' => '2027-03-25 10:00:00']);

    inWorkspace($this->workspace, fn () => app(ApprovePost::class)->handle($this->owner, $post));

    expect(freshPost($post)->state)->toBe(PostState::Scheduled);
    $this->fake->assertNothingPublished();
})->with('workspace clocks');

it('refuses to schedule an approved post for after the access ends, to the minute', function (string $timezone, string $date) {
    ($this->setUpWorkspace)($timezone);
    $post = postInState($this->workspace, PostState::Approved, [$this->channel]);
    $schedule = fn (string $at) => inWorkspace($this->workspace, fn () => app(SchedulePost::class)->handle($this->owner, $post, CarbonImmutable::parse($at)));

    expect(fn () => $schedule('2027-01-24 22:30:00'))->toThrow(
        fn (PostNotPublishable $exception) => expect($exception->errors())->toBe(['scheduled_at' => [($this->message)($date)]]),
    );

    expect(freshPost($post)->state)->toBe(PostState::Approved);

    $schedule('2027-01-24 22:29:00');

    expect(freshPost($post)->state)->toBe(PostState::Scheduled);
})->with('workspace clocks');

it('has nothing to say when the post is due before the access ends, or the access has no end', function () {
    ($this->setUpWorkspace)('Africa/Johannesburg');
    $early = postInState($this->workspace, PostState::Draft, [$this->channel], CarbonImmutable::parse('2027-01-10 09:00:00'));
    $forever = channelIn($this->workspace, ['display_name' => 'A Page', 'token_expires_at' => null]);
    $late = postInState($this->workspace, PostState::Draft, [$forever], CarbonImmutable::parse('2027-02-01 09:00:00'));
    $unplanned = postInState($this->workspace, PostState::Draft, [$this->channel]);

    $this->actingAs($this->owner);

    foreach ([$early, $late, $unplanned] as $post) {
        $this->get(route('posts.show', $post->id))->assertInertia(fn (Assert $page) => $page->where('post.problems', []));
    }
});

it('names every channel of the post whose access ends first, and none that already has the post', function () {
    ($this->setUpWorkspace)('Europe/Copenhagen');
    $second = channelIn($this->workspace, ['display_name' => 'Harbour Books', 'token_expires_at' => '2027-01-28 09:00:00']);
    $done = channelIn($this->workspace, ['display_name' => 'Already there', 'token_expires_at' => '2027-01-20 09:00:00']);
    $post = postInState($this->workspace, PostState::Draft, [$this->channel, $second, $done], CarbonImmutable::parse('2027-02-01 09:00:00'));

    DB::table('post_variants')->where('post_id', $post->id)->where('channel_id', $done->id)
        ->update(['external_id' => 'ext_1', 'published_at' => now()]);

    $this->actingAs($this->owner)->get(route('posts.show', $post->id))->assertInertia(fn (Assert $page) => $page
        ->where('post.problems.scheduled_at', [
            ($this->message)('24 January 2027'),
            'The access to Harbour Books ends on 28 January 2027, before this post is due. Connect Harbour Books again on the Channels page before then, or choose an earlier time.',
        ]));
});

it('leaves an access that has already ended to the message about the channel', function () {
    ($this->setUpWorkspace)('Europe/Copenhagen');
    $post = postInState($this->workspace, PostState::Draft, [$this->channel], CarbonImmutable::parse('2027-02-01 09:00:00'));

    $this->travelTo('2027-01-26 10:00:00');

    $this->actingAs($this->owner)->get(route('posts.show', $post->id))->assertInertia(fn (Assert $page) => $page
        ->where('post.problems', ['variants.'.variantsOf($post)[0]->id.'.channel' => ['The access to Thandi Mokoena has expired. Connect it again.']]));
});
