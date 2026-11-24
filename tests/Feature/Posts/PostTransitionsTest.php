<?php

use App\Domain\Channels\Contracts\ChannelCapabilities;
use App\Domain\Content\Models\Post;
use App\Domain\Content\PostState;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';

beforeEach(function () {
    $this->travelTo('2026-11-24 10:00:00');
    Http::preventStrayRequests();

    $this->fake = fakeChannelAdapter();
    $this->workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->owner = userWithRole($this->workspace, Role::Owner);
    $this->channel = channelIn($this->workspace, ['display_name' => 'Alpha']);
});

/**
 * Every state change the pages offer: the route, a state it starts from,
 * what the request carries and the state it ends in.
 *
 * @return array<string, array{string, PostState, Closure(Post): array<string, mixed>, PostState}>
 */
function postTransitions(): array
{
    $nothing = fn (Post $post): array => [];

    return [
        'approve' => ['posts.approve', PostState::Draft, fn (Post $post): array => ['version' => postVersion($post)], PostState::Approved],
        'reject' => ['posts.reject', PostState::Draft, $nothing, PostState::Rejected],
        'schedule' => ['posts.schedule', PostState::Approved, fn (Post $post): array => ['scheduled_at' => '2026-11-25T09:00'], PostState::Scheduled],
        'move' => ['posts.schedule', PostState::Scheduled, fn (Post $post): array => ['scheduled_at' => '2026-11-26T09:00'], PostState::Scheduled],
        'unschedule' => ['posts.unschedule', PostState::Scheduled, $nothing, PostState::Approved],
        'return an approved post to draft' => ['posts.reopen', PostState::Approved, $nothing, PostState::Draft],
        'return a scheduled post to draft' => ['posts.reopen', PostState::Scheduled, $nothing, PostState::Draft],
        'return a failed post to draft' => ['posts.reopen', PostState::Failed, $nothing, PostState::Draft],
        'return a rejected post to draft' => ['posts.reopen', PostState::Rejected, $nothing, PostState::Draft],
        'retry' => ['posts.retry', PostState::Failed, $nothing, PostState::Scheduled],
    ];
}

describe('every state change', function () {
    it('works for owners and editors, and comes back to the page it was made on', function (string $route, PostState $from, Closure $payload, PostState $to) {
        foreach ([Role::Owner, Role::Editor] as $role) {
            $post = postInState($this->workspace, $from, [$this->channel]);

            $this->actingAs(userWithRole($this->workspace, $role))
                ->from(route('queue.index'))
                ->post(route($route, $post->id), $payload($post))
                ->assertRedirect(route('queue.index'))
                ->assertSessionHasNoErrors();

            expect(stateOf($post))->toBe($to->value, $role->value);
        }

        $this->fake->assertNothingPublished();
    })->with(postTransitions());

    it('is refused for viewers', function (string $route, PostState $from, Closure $payload) {
        $post = postInState($this->workspace, $from, [$this->channel]);
        $before = DB::table('posts')->where('id', $post->id)->first();

        $this->actingAs(userWithRole($this->workspace, Role::Viewer))
            ->post(route($route, $post->id), $payload($post))
            ->assertForbidden();

        expect(DB::table('posts')->where('id', $post->id)->first())->toEqual($before);
    })->with(postTransitions());

    it('cannot reach a post of another workspace by its id', function (string $route, PostState $from, Closure $payload) {
        $post = postInState($this->workspace, $from, [$this->channel]);
        $before = DB::table('posts')->where('id', $post->id)->first();

        $this->actingAs(userWithRole(Workspace::factory()->create(), Role::Owner))
            ->post(route($route, $post->id), $payload($post))
            ->assertNotFound();

        expect(DB::table('posts')->where('id', $post->id)->first())->toEqual($before);
    })->with(postTransitions());

    it('sends guests to the login page', function (string $route, PostState $from, Closure $payload) {
        $post = postInState($this->workspace, $from, [$this->channel]);
        $before = DB::table('posts')->where('id', $post->id)->first();

        $this->post(route($route, $post->id), $payload($post))->assertRedirect(route('login'));

        expect(DB::table('posts')->where('id', $post->id)->first())->toEqual($before);
    })->with(postTransitions());

    it('answers 404 for a post that does not exist', function (string $route) {
        $this->actingAs($this->owner)
            ->post(route($route, 999999), ['version' => 'x', 'scheduled_at' => '2026-11-25T09:00'])
            ->assertNotFound();
    })->with(['posts.approve', 'posts.reject', 'posts.schedule', 'posts.unschedule', 'posts.reopen', 'posts.retry']);

    it('says so, and changes nothing, when the post is not in a state that allows it', function (string $route, PostState $state, string $message) {
        $post = postInState($this->workspace, $state, [$this->channel]);
        $before = DB::table('posts')->where('id', $post->id)->first();

        $this->actingAs($this->owner)
            ->from(route('posts.show', $post->id))
            ->post(route($route, $post->id), ['version' => postVersion($post), 'scheduled_at' => '2026-11-25T09:00'])
            ->assertRedirect(route('posts.show', $post->id))
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => $message]);

        expect(DB::table('posts')->where('id', $post->id)->first())->toEqual($before);

        $this->fake->assertNothingPublished();
    })->with([
        'approving an approved post' => ['posts.approve', PostState::Approved, 'That is not possible: the post is approved now.'],
        'approving a rejected post' => ['posts.approve', PostState::Rejected, 'That is not possible: the post is rejected now.'],
        'approving a failed post' => ['posts.approve', PostState::Failed, 'That is not possible: the post has failed.'],
        'rejecting a scheduled post' => ['posts.reject', PostState::Scheduled, 'That is not possible: the post is scheduled now.'],
        'scheduling a draft' => ['posts.schedule', PostState::Draft, 'That is not possible: the post is a draft now.'],
        'scheduling a published post' => ['posts.schedule', PostState::Published, 'That is not possible: the post is published now.'],
        'unscheduling a post that is being published' => ['posts.unschedule', PostState::Publishing, 'That is not possible: the post is being published now.'],
        'returning a published post to draft' => ['posts.reopen', PostState::Published, 'That is not possible: the post is published now.'],
        'returning a post that is being published to draft' => ['posts.reopen', PostState::Publishing, 'That is not possible: the post is being published now.'],
        'retrying a scheduled post' => ['posts.retry', PostState::Scheduled, 'That is not possible: the post is scheduled now.'],
    ]);
});

describe('approving', function () {
    it('records who approved and leaves a post without a time out of the schedule', function () {
        $post = postInState($this->workspace, PostState::Draft, [$this->channel]);
        $editor = userWithRole($this->workspace, Role::Editor);

        $this->actingAs($editor)
            ->from(route('posts.show', $post->id))
            ->post(route('posts.approve', $post->id), ['version' => postVersion($post)])
            ->assertRedirect(route('posts.show', $post->id))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'The post is approved. It has no time yet.']);

        $fresh = freshPost($post);

        expect($fresh->state)->toBe(PostState::Approved)
            ->and($fresh->approved_by)->toBe($editor->id)
            ->and($fresh->approved_at?->toDateTimeString())->toBe('2026-11-24 10:00:00')
            ->and($fresh->scheduled_at)->toBeNull();

        $this->get(route('posts.show', $post->id))->assertInertia(fn (Assert $page) => $page
            ->where('post.state', 'approved')
            ->where('post.approval', ['at' => '2026-11-24T10:00:00+00:00', 'by' => $editor->name]));
    });

    it('schedules a draft that has a planned time in the same step', function () {
        $post = postInState($this->workspace, PostState::Draft, [$this->channel], now()->addDay());

        $this->actingAs($this->owner)
            ->post(route('posts.approve', $post->id), ['version' => postVersion($post)])
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'The post is approved and scheduled.']);

        $fresh = freshPost($post);

        expect($fresh->state)->toBe(PostState::Scheduled)
            ->and($fresh->approved_by)->toBe($this->owner->id)
            ->and($fresh->scheduled_at?->toDateTimeString())->toBe('2026-11-25 10:00:00');
    });

    it('needs the fingerprint of the content that was on screen', function () {
        $post = postInState($this->workspace, PostState::Draft, [$this->channel]);

        $this->actingAs($this->owner)
            ->post(route('posts.approve', $post->id))
            ->assertSessionHasErrors('version');

        expect(stateOf($post))->toBe('draft');
    });

    it('refuses to approve a post that was edited after it was shown', function () {
        $post = postInState($this->workspace, PostState::Draft, [$this->channel]);
        $seen = postVersion($post);

        setBody($post, 'Something nobody has read yet.');

        $this->actingAs($this->owner)
            ->post(route('posts.approve', $post->id), ['version' => $seen])
            ->assertSessionHasErrors(['version' => 'The post was changed after you opened it. Read it again before you approve it.']);

        expect(stateOf($post))->toBe('draft')
            ->and(freshPost($post)->approved_at)->toBeNull();

        // A changed planned time counts as well.
        $seen = postVersion($post);
        DB::table('posts')->where('id', $post->id)->update(['scheduled_at' => now()->addMinutes(5)]);

        $this->post(route('posts.approve', $post->id), ['version' => $seen])->assertSessionHasErrors('version');

        expect(stateOf($post))->toBe('draft');
    });

    it('brings what stands in the way back to the form', function (Closure $arrange, Closure $errors) {
        $this->fake->withCapabilities(new ChannelCapabilities(maxBodyLength: 20));

        $post = postInState($this->workspace, PostState::Draft, [$this->channel]);
        setBody($post, 'Short enough.');
        $arrange($post, $this);
        $expected = $errors($post);

        $this->actingAs($this->owner)
            ->from(route('posts.show', $post->id))
            ->post(route('posts.approve', $post->id), ['version' => postVersion($post)])
            ->assertRedirect(route('posts.show', $post->id));

        // The page the browser lands on carries the messages. Read there and
        // not with assertSessionHasErrors(), which empties the error bag for
        // the request that follows.
        $this->get(route('posts.show', $post->id))->assertInertia(fn (Assert $page) => $page
            ->where('errors', $expected)
            ->where('post.state', 'draft'));

        expect(stateOf($post))->toBe('draft')
            ->and(freshPost($post)->approved_at)->toBeNull()
            ->and(freshPost($post)->approved_by)->toBeNull();
    })->with([
        'an empty text' => [
            fn (Post $post) => setBody($post, ''),
            fn (Post $post) => ['variants.'.variantsOf($post)[0]->id.'.body' => 'The text for Alpha is empty.'],
        ],
        'a text that is too long' => [
            fn (Post $post) => setBody($post, 'This is longer than twenty characters.'),
            fn (Post $post) => ['variants.'.variantsOf($post)[0]->id.'.body' => 'The text for Alpha is 38 characters long. The limit is 20.'],
        ],
        'a channel that must be connected again' => [
            fn (Post $post, $test) => inWorkspace($test->workspace, fn () => $test->channel->markNeedsReconnect()),
            fn (Post $post) => ['variants.'.variantsOf($post)[0]->id.'.channel' => 'Alpha must be connected again before it can publish.'],
        ],
        'no channel at all' => [
            fn (Post $post) => DB::table('post_variants')->where('post_id', $post->id)->delete(),
            fn (Post $post) => ['variants' => 'The post has no text for any channel. Add at least one channel.'],
        ],
        'a planned time that has passed' => [
            fn (Post $post) => DB::table('posts')->where('id', $post->id)->update(['scheduled_at' => now()->subHour()]),
            fn (Post $post) => ['scheduled_at' => 'The scheduled time has passed. Choose a time in the future.'],
        ],
    ]);
});

describe('approving a selection from the queue', function () {
    it('approves exactly the drafts that were ticked', function () {
        $first = postInState($this->workspace, PostState::Draft, [$this->channel]);
        $second = postInState($this->workspace, PostState::Draft, [$this->channel], now()->addDay());
        $untouched = postInState($this->workspace, PostState::Draft, [$this->channel]);

        $this->actingAs($this->owner)
            ->from(route('queue.index'))
            ->post(route('queue.approve'), ['posts' => [
                ['id' => $first->id, 'version' => postVersion($first)],
                ['id' => $second->id, 'version' => postVersion($second)],
            ]])
            ->assertRedirect(route('queue.index'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '2 posts are approved.']);

        expect(stateOf($first))->toBe('approved')
            ->and(stateOf($second))->toBe('scheduled')
            ->and(freshPost($first)->approved_by)->toBe($this->owner->id)
            ->and(stateOf($untouched))->toBe('draft')
            ->and(freshPost($untouched)->approved_at)->toBeNull();
    });

    it('leaves a draft alone when it is not ready or was edited, and approves the rest', function () {
        $ready = postInState($this->workspace, PostState::Draft, [$this->channel]);
        $empty = postInState($this->workspace, PostState::Draft, [$this->channel]);
        setBody($empty, '');
        $edited = postInState($this->workspace, PostState::Draft, [$this->channel]);
        $seen = postVersion($edited);
        setBody($edited, 'Changed since.');
        $already = postInState($this->workspace, PostState::Rejected, [$this->channel]);

        $this->actingAs($this->owner)
            ->post(route('queue.approve'), ['posts' => [
                ['id' => $ready->id, 'version' => postVersion($ready)],
                ['id' => $empty->id, 'version' => postVersion($empty)],
                ['id' => $edited->id, 'version' => $seen],
                ['id' => $already->id, 'version' => postVersion($already)],
            ]])
            ->assertInertiaFlash('toast', [
                'type' => 'warning',
                'message' => '1 approved, 3 not approved. Open what is left to see why.',
            ]);

        expect(stateOf($ready))->toBe('approved')
            ->and(stateOf($empty))->toBe('draft')
            ->and(stateOf($edited))->toBe('draft')
            ->and(stateOf($already))->toBe('rejected');
    });

    it('approves nothing without a selection', function () {
        $post = postInState($this->workspace, PostState::Draft, [$this->channel]);

        $this->actingAs($this->owner)
            ->post(route('queue.approve'), ['posts' => []])
            ->assertSessionHasErrors(['posts' => 'Select at least one draft.']);

        $this->post(route('queue.approve'), ['posts' => [['id' => $post->id]]])
            ->assertSessionHasErrors('posts.0.version');

        expect(stateOf($post))->toBe('draft');
    });

    it('approves nothing when the selection names a post of another workspace', function () {
        $mine = postInState($this->workspace, PostState::Draft, [$this->channel]);
        $theirs = postInState(Workspace::factory()->create(), PostState::Draft, 1);

        $this->actingAs($this->owner)
            ->post(route('queue.approve'), ['posts' => [
                ['id' => $mine->id, 'version' => postVersion($mine)],
                ['id' => $theirs->id, 'version' => postVersion($theirs)],
            ]])
            ->assertNotFound();

        expect(stateOf($mine))->toBe('draft')
            ->and(stateOf($theirs))->toBe('draft');
    });

    it('is refused for viewers and guests', function () {
        $post = postInState($this->workspace, PostState::Draft, [$this->channel]);
        $selection = ['posts' => [['id' => $post->id, 'version' => postVersion($post)]]];

        $this->post(route('queue.approve'), $selection)->assertRedirect(route('login'));

        $this->actingAs(userWithRole($this->workspace, Role::Viewer))
            ->post(route('queue.approve'), $selection)
            ->assertForbidden();

        expect(stateOf($post))->toBe('draft');
    });
});

describe('scheduling', function () {
    it('reads the time on the clock of the workspace and stores it in UTC', function (string $timezone, string $local, string $utc) {
        $this->workspace->update(['timezone' => $timezone]);
        $post = postInState($this->workspace, PostState::Approved, [$this->channel]);

        $this->actingAs($this->owner)
            ->post(route('posts.schedule', $post->id), ['scheduled_at' => $local])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'The post is scheduled.']);

        expect(stateOf($post))->toBe('scheduled')
            ->and(DB::table('posts')->where('id', $post->id)->value('scheduled_at'))->toBe($utc);

        $this->get(route('posts.show', $post->id))
            ->assertInertia(fn (Assert $page) => $page->where('post.scheduled_local', $local));
    })->with([
        'Copenhagen winter (UTC+1)' => ['Europe/Copenhagen', '2026-11-25T09:00', '2026-11-25 08:00:00'],
        'Copenhagen summer (UTC+2)' => ['Europe/Copenhagen', '2027-07-15T12:00', '2027-07-15 10:00:00'],
        'Copenhagen after the clocks go forward' => ['Europe/Copenhagen', '2027-03-28T03:30', '2027-03-28 01:30:00'],
        'Copenhagen after the clocks go back' => ['Europe/Copenhagen', '2027-10-31T03:30', '2027-10-31 02:30:00'],
        'Johannesburg (UTC+2, no DST)' => ['Africa/Johannesburg', '2026-11-25T09:00', '2026-11-25 07:00:00'],
        'Johannesburg in July' => ['Africa/Johannesburg', '2027-07-15T12:00', '2027-07-15 10:00:00'],
        'Johannesburg past midnight' => ['Africa/Johannesburg', '2026-11-26T00:30', '2026-11-25 22:30:00'],
    ]);

    it('goes out at the scheduled minute of the workspace, not before', function (string $timezone, string $justBefore, string $atTheMinute) {
        $this->workspace->update(['timezone' => $timezone]);
        $post = postInState($this->workspace, PostState::Approved, [$this->channel]);

        $this->actingAs($this->owner)
            ->post(route('posts.schedule', $post->id), ['scheduled_at' => '2026-11-25T09:00'])
            ->assertSessionHasNoErrors();

        $this->travelTo($justBefore);
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();
        $this->fake->assertNothingPublished();

        $this->travelTo($atTheMinute);
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();

        $this->fake->assertPublishedOnce(variantsOf($post)[0]);
        expect(stateOf($post))->toBe('published');
    })->with([
        'Copenhagen' => ['Europe/Copenhagen', '2026-11-25 07:59:59', '2026-11-25 08:00:00'],
        'Johannesburg' => ['Africa/Johannesburg', '2026-11-25 06:59:59', '2026-11-25 07:00:00'],
    ]);

    it('can publish at the next run of the scheduler', function () {
        $post = postInState($this->workspace, PostState::Approved, [$this->channel]);

        $this->actingAs($this->owner)
            ->post(route('posts.schedule', $post->id), ['now' => true])
            ->assertSessionHasNoErrors();

        expect(stateOf($post))->toBe('scheduled')
            ->and(freshPost($post)->scheduled_at?->toDateTimeString())->toBe('2026-11-24 10:00:00');
    });

    it('refuses a time that makes no sense', function (array $payload, string $message) {
        $post = postInState($this->workspace, PostState::Approved, [$this->channel]);

        $this->actingAs($this->owner)
            ->from(route('posts.show', $post->id))
            ->post(route('posts.schedule', $post->id), $payload)
            ->assertRedirect(route('posts.show', $post->id))
            ->assertSessionHasErrors(['scheduled_at' => $message]);

        expect(stateOf($post))->toBe('approved')
            ->and(freshPost($post)->scheduled_at)->toBeNull();
    })->with([
        'no time' => [[], 'Choose a date and a time.'],
        'not a time' => [['scheduled_at' => 'soon'], 'The time is not a valid date and time.'],
        // 10:30 in Copenhagen is 09:30 UTC, half an hour ago.
        'a time that has passed' => [['scheduled_at' => '2026-11-24T10:30'], 'The scheduled time has passed. Choose a time in the future.'],
        'more than a year ahead' => [['scheduled_at' => '2028-01-01T09:00'], 'The scheduled time is more than a year ahead.'],
    ]);

    it('refuses to schedule for a channel that can no longer publish', function () {
        $post = postInState($this->workspace, PostState::Approved, [$this->channel]);
        inWorkspace($this->workspace, fn () => $this->channel->disconnect());

        $this->actingAs($this->owner)
            ->post(route('posts.schedule', $post->id), ['scheduled_at' => '2026-11-25T09:00'])
            ->assertSessionHasErrors(['variants.'.variantsOf($post)[0]->id.'.channel' => 'Alpha is disconnected.']);

        expect(stateOf($post))->toBe('approved');
    });
});

describe('returning to draft', function () {
    it('removes the approval, so the post cannot go out until it is approved again', function () {
        $post = postInState($this->workspace, PostState::Scheduled, [$this->channel], now()->addMinute());

        $this->actingAs($this->owner)
            ->post(route('posts.reopen', $post->id))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'The post is a draft again. Its approval is removed.']);

        $fresh = freshPost($post);

        expect($fresh->state)->toBe(PostState::Draft)
            ->and($fresh->approved_at)->toBeNull()
            ->and($fresh->approved_by)->toBeNull();

        $this->travel(10)->minutes();
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();

        $this->fake->assertNothingPublished();
        expect(stateOf($post))->toBe('draft');

        // It can be edited again.
        $this->put(route('posts.update', $post->id), [
            'scheduled_at' => null,
            'variants' => [['channel_id' => $this->channel->id, 'body' => 'Second version.', 'link' => null]],
        ])->assertSessionHasNoErrors();

        expect(variantsOf($post)[0]->body)->toBe('Second version.');
    });
});

describe('retrying', function () {
    it('puts a failed post back in the schedule for now, with its approval', function () {
        $post = postInState($this->workspace, PostState::Failed, [$this->channel]);
        $approvedBy = freshPost($post)->approved_by;

        $this->actingAs($this->owner)
            ->post(route('posts.retry', $post->id))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'The post is in the queue to be sent again.']);

        $fresh = freshPost($post);

        expect($fresh->state)->toBe(PostState::Scheduled)
            ->and($fresh->scheduled_at?->toDateTimeString())->toBe('2026-11-24 10:00:00')
            ->and($fresh->approved_by)->toBe($approvedBy);

        // The request itself sends nothing; the scheduler does.
        $this->fake->assertNothingPublished();

        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();

        $this->fake->assertPublishedOnce(variantsOf($post)[0]);
        expect(stateOf($post))->toBe('published');
    });

    it('is refused while the channel cannot publish', function () {
        $post = postInState($this->workspace, PostState::Failed, [$this->channel]);
        inWorkspace($this->workspace, fn () => $this->channel->markNeedsReconnect());

        $this->actingAs($this->owner)
            ->post(route('posts.retry', $post->id))
            ->assertSessionHasErrors(['variants.'.variantsOf($post)[0]->id.'.channel' => 'Alpha must be connected again before it can publish.']);

        expect(stateOf($post))->toBe('failed');
    });
});
