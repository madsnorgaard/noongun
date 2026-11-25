<?php

use App\Domain\Channels\Contracts\ChannelCapabilities;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Actions\ApprovePost;
use App\Domain\Content\Actions\RejectPost;
use App\Domain\Content\Actions\RetryPost;
use App\Domain\Content\Actions\ReturnPostToDraft;
use App\Domain\Content\Actions\SchedulePost;
use App\Domain\Content\Actions\UnschedulePost;
use App\Domain\Content\Exceptions\IllegalPostTransition;
use App\Domain\Content\Exceptions\PostActionNotAllowed;
use App\Domain\Content\Exceptions\PostNotPublishable;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostPreconditions;
use App\Domain\Content\PostState;
use App\Domain\Workspaces\Exceptions\CrossWorkspaceWrite;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../../Support/publishing.php';

beforeEach(function () {
    $this->travelTo('2026-11-24 10:00:00');

    $this->fake = fakeChannelAdapter();
    $this->workspace = Workspace::factory()->create();
    $this->editor = userWithRole($this->workspace, Role::Editor);
});

/**
 * Each action with the state it starts from and a call that runs it.
 *
 * @return array<string, array{PostState, Closure(User, Post): Post, PostState}>
 */
function postActions(): array
{
    return [
        'ApprovePost' => [PostState::Draft, fn (User $actor, Post $post) => app(ApprovePost::class)->handle($actor, $post), PostState::Approved],
        'RejectPost' => [PostState::Draft, fn (User $actor, Post $post) => app(RejectPost::class)->handle($actor, $post), PostState::Rejected],
        'SchedulePost' => [PostState::Approved, fn (User $actor, Post $post) => app(SchedulePost::class)->handle($actor, $post, now()->addDay()), PostState::Scheduled],
        'UnschedulePost' => [PostState::Scheduled, fn (User $actor, Post $post) => app(UnschedulePost::class)->handle($actor, $post), PostState::Approved],
        'ReturnPostToDraft' => [PostState::Approved, fn (User $actor, Post $post) => app(ReturnPostToDraft::class)->handle($actor, $post), PostState::Draft],
        'RetryPost' => [PostState::Failed, fn (User $actor, Post $post) => app(RetryPost::class)->handle($actor, $post), PostState::Scheduled],
    ];
}

describe('authorization', function () {
    it('lets an owner and an editor act', function (PostState $from, Closure $act, PostState $to, Role $role) {
        $post = postInState($this->workspace, $from);
        $actor = userWithRole($this->workspace, $role);

        $result = inWorkspace($this->workspace, fn () => $act($actor, $post));

        expect($result)->toBe($post)
            ->and(freshPost($post)->state)->toBe($to);
    })->with(postActions())->with([Role::Owner, Role::Editor]);

    it('refuses a viewer', function (PostState $from, Closure $act) {
        $post = postInState($this->workspace, $from);
        $viewer = userWithRole($this->workspace, Role::Viewer);

        expect(fn () => inWorkspace($this->workspace, fn () => $act($viewer, $post)))
            ->toThrow(PostActionNotAllowed::class);

        expect(freshPost($post)->state)->toBe($from);
    })->with(postActions());

    it('refuses a user who is not a member, even an owner of another workspace', function (PostState $from, Closure $act) {
        $post = postInState($this->workspace, $from);
        $stranger = userWithRole(Workspace::factory()->create(), Role::Owner);

        expect(fn () => inWorkspace($this->workspace, fn () => $act($stranger, $post)))
            ->toThrow(PostActionNotAllowed::class);

        expect(freshPost($post)->state)->toBe($from);
    })->with(postActions());

    it('refuses a user who was never saved', function (PostState $from, Closure $act) {
        $post = postInState($this->workspace, $from);

        expect(fn () => inWorkspace($this->workspace, fn () => $act(User::factory()->make(), $post)))
            ->toThrow(PostActionNotAllowed::class);
    })->with(postActions());

    it('reports a refusal as an authorization exception, which renders as 403', function () {
        expect(is_subclass_of(PostActionNotAllowed::class, AuthorizationException::class))->toBeTrue();
    });

    it('cannot act on a post from inside another workspace', function (PostState $from, Closure $act) {
        $post = postInState($this->workspace, $from);

        expect(fn () => inWorkspace(Workspace::factory()->create(), fn () => $act($this->editor, $post)))
            ->toThrow(CrossWorkspaceWrite::class);

        expect(freshPost($post)->state)->toBe($from);
    })->with(postActions());
});

describe('ApprovePost', function () {
    it('approves a draft in the name of the acting user', function () {
        $post = postInState($this->workspace, PostState::Draft, 2);

        inWorkspace($this->workspace, fn () => app(ApprovePost::class)->handle($this->editor, $post));

        expect($post->state)->toBe(PostState::Approved)
            ->and($post->approved_by)->toBe($this->editor->id)
            ->and($post->approved_at?->equalTo(now()))->toBeTrue()
            ->and($post->scheduled_at)->toBeNull();
    });

    it('schedules in the same step when the draft has a planned time', function () {
        $post = postInState($this->workspace, PostState::Draft, 1, now()->addDays(2));

        inWorkspace($this->workspace, fn () => app(ApprovePost::class)->handle($this->editor, $post));

        expect($post->state)->toBe(PostState::Scheduled)
            ->and($post->approved_by)->toBe($this->editor->id)
            ->and($post->scheduled_at?->toDateTimeString())->toBe('2026-11-26 10:00:00');
    });

    it('refuses a planned time that has passed and leaves the draft unapproved', function () {
        $post = postInState($this->workspace, PostState::Draft, 1, now()->subHour());

        try {
            inWorkspace($this->workspace, fn () => app(ApprovePost::class)->handle($this->editor, $post));
            $this->fail('Expected PostNotPublishable.');
        } catch (PostNotPublishable $e) {
            expect($e->errors())->toHaveKey('scheduled_at');
        }

        expect($post->state)->toBe(PostState::Draft)
            ->and(freshPost($post)->state)->toBe(PostState::Draft)
            ->and(freshPost($post)->approved_at)->toBeNull()
            ->and(freshPost($post)->approved_by)->toBeNull();
    });

    it('refuses a post without variants', function () {
        $post = inWorkspace($this->workspace, fn () => Post::factory()->create());

        try {
            inWorkspace($this->workspace, fn () => app(ApprovePost::class)->handle($this->editor, $post));
            $this->fail('Expected PostNotPublishable.');
        } catch (PostNotPublishable $e) {
            expect($e->errors())->toHaveKeys(['variants'])
                ->and($e->post)->toBe($post)
                ->and($e->getMessage())->toBe($e->errors()['variants'][0]);
        }

        expect(freshPost($post)->state)->toBe(PostState::Draft);
    });

    it('refuses a variant whose channel cannot publish', function (Closure $channel, string $expected) {
        $channel = inWorkspace($this->workspace, fn () => $channel()->create(['display_name' => 'Harbour News']));
        $post = postInState($this->workspace, PostState::Draft, [$channel]);
        $variant = variantsOf($post)[0];

        try {
            inWorkspace($this->workspace, fn () => app(ApprovePost::class)->handle($this->editor, $post));
            $this->fail('Expected PostNotPublishable.');
        } catch (PostNotPublishable $e) {
            expect($e->errors())->toBe(["variants.{$variant->id}.channel" => [$expected]]);
        }

        expect(freshPost($post)->state)->toBe(PostState::Draft);
    })->with([
        'needs reconnect' => [fn () => Channel::factory()->needsReconnect(), 'Harbour News must be connected again before it can publish.'],
        'disconnected' => [fn () => Channel::factory()->disconnected(), 'Harbour News is disconnected.'],
        'token expired' => [fn () => Channel::factory()->expired(), 'The access to Harbour News has expired. Connect it again.'],
        'no adapter for the platform' => [fn () => Channel::factory()->platform('telegraph'), 'Publishing to telegraph is not available on this instance.'],
    ]);

    it('refuses a text that is longer than the platform accepts', function () {
        $this->fake->withCapabilities(new ChannelCapabilities(maxBodyLength: 10));
        $post = postInState($this->workspace, PostState::Draft);
        $variant = variantsOf($post)[0];

        try {
            inWorkspace($this->workspace, fn () => app(ApprovePost::class)->handle($this->editor, $post));
            $this->fail('Expected PostNotPublishable.');
        } catch (PostNotPublishable $e) {
            expect($e->errors())->toHaveKeys(["variants.{$variant->id}.body"])
                ->and($e->errors()["variants.{$variant->id}.body"][0])->toContain('The limit is 10.');
        }

        expect(freshPost($post)->state)->toBe(PostState::Draft);
    });

    it('accepts a text of exactly the longest length, counted in characters', function () {
        $this->fake->withCapabilities(new ChannelCapabilities(maxBodyLength: 5));
        $post = inWorkspace($this->workspace, function () {
            $post = Post::factory()->create();
            PostVariant::factory()->create(['post_id' => $post->id, 'body' => 'æøå é']);

            return $post;
        });

        inWorkspace($this->workspace, fn () => app(ApprovePost::class)->handle($this->editor, $post));

        expect($post->state)->toBe(PostState::Approved);
    });

    it('refuses an empty text', function () {
        $post = inWorkspace($this->workspace, function () {
            $post = Post::factory()->create();
            PostVariant::factory()->create(['post_id' => $post->id, 'body' => "  \n "]);

            return $post;
        });

        expect(fn () => inWorkspace($this->workspace, fn () => app(ApprovePost::class)->handle($this->editor, $post)))
            ->toThrow(PostNotPublishable::class, 'is empty');
    });

    it('reports every variant that has a problem', function () {
        $channels = inWorkspace($this->workspace, fn () => [
            Channel::factory()->create(),
            Channel::factory()->disconnected()->create(),
            Channel::factory()->needsReconnect()->create(),
        ]);
        $post = postInState($this->workspace, PostState::Draft, $channels);

        try {
            inWorkspace($this->workspace, fn () => app(ApprovePost::class)->handle($this->editor, $post));
            $this->fail('Expected PostNotPublishable.');
        } catch (PostNotPublishable $e) {
            expect($e->errors())->toHaveCount(2);
        }
    });

    it('only approves a draft', function (PostState $state) {
        $post = postInState($this->workspace, $state);
        $before = DB::table('posts')->where('id', $post->id)->first();

        expect(fn () => inWorkspace($this->workspace, fn () => app(ApprovePost::class)->handle($this->editor, $post)))
            ->toThrow(IllegalPostTransition::class);

        expect(DB::table('posts')->where('id', $post->id)->first())->toEqual($before);
    })->with([
        PostState::Approved, PostState::Scheduled, PostState::Publishing,
        PostState::Published, PostState::Failed, PostState::Rejected,
    ]);

    it('never calls a platform', function () {
        $post = postInState($this->workspace, PostState::Draft, 1, now()->addMinute());

        inWorkspace($this->workspace, fn () => app(ApprovePost::class)->handle($this->editor, $post));

        expect($this->fake->calls)->toBe([]);
    });
});

describe('SchedulePost', function () {
    it('schedules an approved post and stores the time in UTC', function () {
        $post = postInState($this->workspace, PostState::Approved);
        $at = new DateTimeImmutable('2026-12-01 09:00:00', new DateTimeZone('Africa/Johannesburg'));

        inWorkspace($this->workspace, fn () => app(SchedulePost::class)->handle($this->editor, $post, $at));

        expect($post->state)->toBe(PostState::Scheduled)
            ->and(DB::table('posts')->where('id', $post->id)->value('scheduled_at'))->toBe('2026-12-01 07:00:00');
    });

    it('moves a scheduled post to another time', function () {
        $post = postInState($this->workspace, PostState::Scheduled);

        inWorkspace($this->workspace, fn () => app(SchedulePost::class)->handle($this->editor, $post, now()->addDays(3)));

        expect($post->state)->toBe(PostState::Scheduled)
            ->and($post->scheduled_at?->toDateTimeString())->toBe('2026-11-27 10:00:00');
    });

    it('accepts now, so that a post can go out at the next minute', function () {
        $post = postInState($this->workspace, PostState::Approved);

        inWorkspace($this->workspace, fn () => app(SchedulePost::class)->handle($this->editor, $post, now()->subSeconds(30)));

        expect($post->state)->toBe(PostState::Scheduled);
    });

    it('refuses a time that is not sensible', function (string $at) {
        $post = postInState($this->workspace, PostState::Approved);

        try {
            inWorkspace($this->workspace, fn () => app(SchedulePost::class)->handle($this->editor, $post, new DateTimeImmutable($at)));
            $this->fail('Expected PostNotPublishable.');
        } catch (PostNotPublishable $e) {
            expect($e->errors())->toHaveKeys(['scheduled_at']);
        }

        expect(freshPost($post)->state)->toBe(PostState::Approved)
            ->and(freshPost($post)->scheduled_at)->toBeNull();
    })->with([
        'two minutes ago' => ['2026-11-24 09:58:00'],
        'last year' => ['2025-11-24 10:00:00'],
        'more than a year ahead' => ['2027-11-27 10:00:00'],
        'a typo in the year' => ['2206-11-24 10:00:00'],
    ]);

    it('refuses when a channel was disconnected after the approval', function () {
        $channel = inWorkspace($this->workspace, fn () => Channel::factory()->create());
        $post = postInState($this->workspace, PostState::Approved, [$channel]);
        inWorkspace($this->workspace, fn () => $channel->disconnect());

        expect(fn () => inWorkspace($this->workspace, fn () => app(SchedulePost::class)->handle($this->editor, $post, now()->addHour())))
            ->toThrow(PostNotPublishable::class, 'is disconnected');

        expect(freshPost($post)->state)->toBe(PostState::Approved);
    });

    it('does not schedule a post that was never approved', function (PostState $state) {
        $post = postInState($this->workspace, $state);

        expect(fn () => inWorkspace($this->workspace, fn () => app(SchedulePost::class)->handle($this->editor, $post, now()->addHour())))
            ->toThrow(IllegalPostTransition::class);

        expect(freshPost($post)->state)->toBe($state);
    })->with([PostState::Draft, PostState::Rejected, PostState::Publishing, PostState::Published, PostState::Failed]);
});

describe('UnschedulePost, ReturnPostToDraft and RejectPost', function () {
    it('takes a scheduled post out of the queue and keeps its approval and time', function () {
        $post = postInState($this->workspace, PostState::Scheduled, 1, now()->addHour());

        inWorkspace($this->workspace, fn () => app(UnschedulePost::class)->handle($this->editor, $post));

        expect($post->state)->toBe(PostState::Approved)
            ->and($post->approved_at)->not->toBeNull()
            ->and($post->scheduled_at?->toDateTimeString())->toBe('2026-11-24 11:00:00');
    });

    it('cannot unschedule a post the scheduler has claimed', function () {
        $post = postInState($this->workspace, PostState::Scheduled, 1, now()->subMinute());
        $stale = freshPost($post);
        inWorkspace($this->workspace, fn () => $post->claimForPublishing());

        expect(fn () => inWorkspace($this->workspace, fn () => app(UnschedulePost::class)->handle($this->editor, $stale)))
            ->toThrow(IllegalPostTransition::class);

        expect(freshPost($post)->state)->toBe(PostState::Publishing);
    });

    it('erases the approval when a post returns to draft', function (PostState $state) {
        $post = postInState($this->workspace, $state);

        inWorkspace($this->workspace, fn () => app(ReturnPostToDraft::class)->handle($this->editor, $post));

        expect($post->state)->toBe(PostState::Draft)
            ->and($post->approved_at)->toBeNull()
            ->and($post->approved_by)->toBeNull();
    })->with([PostState::Approved, PostState::Scheduled, PostState::Failed, PostState::Rejected]);

    it('cannot return a post to draft while or after it is published', function (PostState $state) {
        $post = postInState($this->workspace, $state);

        expect(fn () => inWorkspace($this->workspace, fn () => app(ReturnPostToDraft::class)->handle($this->editor, $post)))
            ->toThrow(IllegalPostTransition::class);
    })->with([PostState::Draft, PostState::Publishing, PostState::Published]);

    it('only rejects a draft', function (PostState $state) {
        $post = postInState($this->workspace, $state);

        expect(fn () => inWorkspace($this->workspace, fn () => app(RejectPost::class)->handle($this->editor, $post)))
            ->toThrow(IllegalPostTransition::class);
    })->with([PostState::Approved, PostState::Scheduled, PostState::Publishing, PostState::Published, PostState::Failed, PostState::Rejected]);
});

describe('RetryPost', function () {
    it('puts a failed post back in the queue for now, with its approval', function () {
        $post = postInState($this->workspace, PostState::Failed);
        $approvedAt = $post->approved_at;
        $this->travel(10)->minutes();

        inWorkspace($this->workspace, fn () => app(RetryPost::class)->handle($this->editor, $post));

        expect($post->state)->toBe(PostState::Scheduled)
            ->and($post->scheduled_at?->toDateTimeString())->toBe('2026-11-24 10:10:00')
            ->and($post->approved_at?->equalTo($approvedAt))->toBeTrue();
    });

    it('can retry at a later time', function () {
        $post = postInState($this->workspace, PostState::Failed);

        inWorkspace($this->workspace, fn () => app(RetryPost::class)->handle($this->editor, $post, now()->addHours(2)));

        expect($post->scheduled_at?->toDateTimeString())->toBe('2026-11-24 12:00:00');
    });

    it('refuses a time in the past', function () {
        $post = postInState($this->workspace, PostState::Failed);

        expect(fn () => inWorkspace($this->workspace, fn () => app(RetryPost::class)->handle($this->editor, $post, now()->subHour())))
            ->toThrow(PostNotPublishable::class);

        expect(freshPost($post)->state)->toBe(PostState::Failed);
    });

    it('refuses while the channel of a missing variant needs to be connected again', function () {
        $channel = inWorkspace($this->workspace, fn () => Channel::factory()->create());
        $post = postInState($this->workspace, PostState::Failed, [$channel]);
        inWorkspace($this->workspace, fn () => $channel->markNeedsReconnect());

        expect(fn () => inWorkspace($this->workspace, fn () => app(RetryPost::class)->handle($this->editor, $post)))
            ->toThrow(PostNotPublishable::class, 'must be connected again');

        expect(freshPost($post)->state)->toBe(PostState::Failed);
    });

    it('ignores the channel of a variant that is already published', function () {
        $channels = inWorkspace($this->workspace, fn () => [Channel::factory()->create(), Channel::factory()->create()]);
        $post = postInState($this->workspace, PostState::Failed, $channels);
        [$published] = variantsOf($post);
        DB::table('post_variants')->where('id', $published->id)->update(['external_id' => 'fb_1', 'published_at' => now(), 'error' => null]);
        inWorkspace($this->workspace, fn () => $channels[0]->disconnect());

        inWorkspace($this->workspace, fn () => app(RetryPost::class)->handle($this->editor, $post));

        expect($post->state)->toBe(PostState::Scheduled);
    });

    it('only retries a failed post', function (PostState $state) {
        $post = postInState($this->workspace, $state);

        expect(fn () => inWorkspace($this->workspace, fn () => app(RetryPost::class)->handle($this->editor, $post)))
            ->toThrow(IllegalPostTransition::class);

        expect(freshPost($post)->state)->toBe($state);
    })->with([PostState::Draft, PostState::Approved, PostState::Scheduled, PostState::Publishing, PostState::Published, PostState::Rejected]);
});

it('names a channel that is waiting to be confirmed', function () {
    $channel = Channel::factory()->pending()->make(['display_name' => 'Unconfirmed']);

    expect(PostPreconditions::channelProblem($channel))
        ->toBe('Unconfirmed is waiting to be confirmed on the Channels page.');
});
