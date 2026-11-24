<?php

use App\Domain\Channels\Contracts\PublishResult;
use App\Domain\Content\Exceptions\ApprovalNotAllowed;
use App\Domain\Content\Exceptions\IllegalPostTransition;
use App\Domain\Content\Models\Post;
use App\Domain\Content\PostState;
use App\Domain\Workspaces\Models\Membership;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

itIsolatesWorkspaces(fn () => Post::factory()->create());

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    $this->editor = User::factory()->create();
    Membership::factory()->role(Role::Editor)->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->editor->id]);
});

/** A post in the given state, in the test's workspace. */
function postIn(PostState $state): Post
{
    $factory = Post::factory()->withVariants(1);

    return inWorkspace(test()->workspace, fn () => (match ($state) {
        PostState::Draft => $factory,
        PostState::Approved => $factory->approved(),
        PostState::Scheduled => $factory->scheduled(),
        PostState::Publishing => $factory->publishing(),
        PostState::Published => $factory->published(),
        PostState::Failed => $factory->failed(),
        PostState::Rejected => $factory->rejected(),
    })->create());
}

it('starts as an unapproved draft', function () {
    $post = inWorkspace($this->workspace, fn () => Post::create([]));

    expect($post->refresh()->state)->toBe(PostState::Draft)
        ->and($post->approved_by)->toBeNull()
        ->and($post->approved_at)->toBeNull()
        ->and($post->isEditable())->toBeTrue();
});

describe('approval can only come from a human action', function () {
    it('ignores state and approval fields in mass assignment', function () {
        $post = inWorkspace($this->workspace, function () {
            $post = Post::create([
                'state' => 'approved',
                'approved_by' => $this->editor->id,
                'approved_at' => now(),
                'scheduled_at' => now()->addDay(),
            ]);

            $post->update(['state' => 'scheduled', 'approved_by' => $this->editor->id, 'approved_at' => now()]);
            $post->fill(['state' => PostState::Approved])->save();

            return $post->refresh();
        });

        expect($post->state)->toBe(PostState::Draft)
            ->and($post->approved_by)->toBeNull()
            ->and($post->approved_at)->toBeNull();
    });

    it('refuses to create a post that is already past draft', function () {
        inWorkspace($this->workspace, function () {
            expect(fn () => (new Post)->forceFill(['state' => PostState::Approved, 'approved_at' => now()])->save())
                ->toThrow(IllegalPostTransition::class)
                ->and(fn () => (new Post)->forceFill(['approved_by' => $this->editor->id])->save())
                ->toThrow(IllegalPostTransition::class);
        });

        expect(DB::table('posts')->count())->toBe(0);
    });

    it('refuses a direct write of state or approval, even when forced', function (array $attributes) {
        $post = postIn(PostState::Draft);

        inWorkspace($this->workspace, function () use ($post, $attributes) {
            $attributes = array_map(fn ($value) => $value === 'editor' ? $this->editor->id : $value, $attributes);

            expect(fn () => $post->forceFill($attributes)->save())->toThrow(IllegalPostTransition::class);
        });

        expect(DB::table('posts')->where('id', $post->id)->value('state'))->toBe('draft');
    })->with([
        'state as enum' => [['state' => PostState::Approved]],
        'state as string' => [['state' => 'scheduled']],
        'approved_by' => [['approved_by' => 'editor']],
        'approved_at' => [['approved_at' => '2026-11-24 12:00:00']],
    ]);

    it('is refused by the database when a row is past draft without an approval', function (string $state) {
        $post = postIn(PostState::Draft);

        expect(fn () => DB::table('posts')->where('id', $post->id)->update(['state' => $state, 'scheduled_at' => now()]))
            ->toThrow(QueryException::class);
    })->with(['approved', 'scheduled', 'publishing', 'published', 'failed']);

    it('is refused by the database when a draft carries an approval', function () {
        $post = postIn(PostState::Draft);

        expect(fn () => DB::table('posts')->where('id', $post->id)->update(['approved_at' => now()]))
            ->toThrow(QueryException::class);
    });

    it('records who approved and when', function () {
        $this->travelTo('2026-11-24 10:15:00');

        $post = postIn(PostState::Draft);
        $returned = inWorkspace($this->workspace, fn () => $post->approve($this->editor));

        expect($returned)->toBe($post)
            ->and($post->state)->toBe(PostState::Approved)
            ->and($post->approved_by)->toBe($this->editor->id)
            ->and($post->approver->is($this->editor))->toBeTrue()
            ->and($post->approved_at->toDateTimeString())->toBe('2026-11-24 10:15:00');
    });

    it('refuses approval by a viewer, an outsider or an unsaved user', function () {
        $viewer = User::factory()->create();
        Membership::factory()->role(Role::Viewer)->create(['workspace_id' => $this->workspace->id, 'user_id' => $viewer->id]);

        $otherOwner = User::factory()->create();
        Membership::factory()->create(['user_id' => $otherOwner->id]);

        $post = postIn(PostState::Draft);

        inWorkspace($this->workspace, function () use ($post, $viewer, $otherOwner) {
            foreach ([$viewer, $otherOwner, User::factory()->make()] as $user) {
                expect(fn () => $post->approve($user))->toThrow(ApprovalNotAllowed::class);
            }
        });

        expect($post->refresh()->state)->toBe(PostState::Draft);
    });

    it('offers approve() as the only public way to the approved state from draft', function () {
        $taking = fn (string $type) => collect((new ReflectionClass(Post::class))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method) => $method->class === Post::class)
            ->filter(fn (ReflectionMethod $method) => collect($method->getParameters())
                ->contains(fn (ReflectionParameter $parameter) => (string) $parameter->getType() === $type))
            ->map->getName()
            ->values()
            ->all();

        expect($taking(User::class))->toBe(['approve'])
            ->and($taking(PostState::class))->toBe([]);
    });
});

describe('transitions', function () {
    it('follows the path from draft to published', function () {
        $post = postIn(PostState::Draft);

        inWorkspace($this->workspace, function () use ($post) {
            $post->approve($this->editor);
            expect($post->state)->toBe(PostState::Approved);

            $post->schedule(now()->subMinute());
            expect($post->state)->toBe(PostState::Scheduled);

            expect($post->claimForPublishing())->toBeTrue()
                ->and($post->state)->toBe(PostState::Publishing);

            $post->variants->each->markPublished(new PublishResult('1_2', null, now()));

            expect($post->finishPublishing())->toBe(PostState::Published)
                ->and($post->state)->toBe(PostState::Published)
                ->and($post->approved_by)->toBe($this->editor->id);
        });
    });

    it('refuses every transition method that is not legal from a state', function (PostState $state) {
        $post = postIn($state);

        $attempts = [
            PostState::Approved->value => [fn () => $post->approve($this->editor), [PostState::Draft]],
            PostState::Rejected->value => [fn () => $post->reject(), [PostState::Draft]],
            PostState::Draft->value => [fn () => $post->returnToDraft(), [PostState::Approved, PostState::Scheduled, PostState::Failed, PostState::Rejected]],
            'schedule' => [fn () => $post->schedule(now()->addHour()), [PostState::Approved, PostState::Scheduled]],
            'unschedule' => [fn () => $post->unschedule(), [PostState::Scheduled]],
            'finish' => [fn () => $post->finishPublishing(), [PostState::Publishing]],
            'retry' => [fn () => $post->retry(), [PostState::Failed]],
        ];

        inWorkspace($this->workspace, function () use ($attempts, $state, $post) {
            foreach ($attempts as $name => [$attempt, $allowedFrom]) {
                if (in_array($state, $allowedFrom, true)) {
                    continue;
                }

                expect($attempt)->toThrow(IllegalPostTransition::class, null, "{$name} from {$state->value}");
                expect($post->refresh()->state)->toBe($state);
            }
        });
    })->with(PostState::cases());

    it('rejects a draft and reopens it', function () {
        $post = postIn(PostState::Draft);

        inWorkspace($this->workspace, function () use ($post) {
            expect($post->reject()->state)->toBe(PostState::Rejected)
                ->and($post->returnToDraft()->state)->toBe(PostState::Draft);
        });
    });

    it('erases the approval when a post returns to draft', function (PostState $state) {
        $post = postIn($state);

        expect($post->approved_at)->not->toBeNull();

        inWorkspace($this->workspace, fn () => $post->returnToDraft());

        expect($post->state)->toBe(PostState::Draft)
            ->and($post->approved_by)->toBeNull()
            ->and($post->approved_at)->toBeNull();
    })->with([PostState::Approved, PostState::Scheduled, PostState::Failed]);

    it('schedules at the planned time when none is given', function () {
        $post = inWorkspace($this->workspace, fn () => Post::factory()->plannedFor(now()->addDays(2)->startOfMinute())->approved()->create());

        inWorkspace($this->workspace, fn () => $post->schedule());

        expect($post->state)->toBe(PostState::Scheduled)
            ->and($post->scheduled_at->eq(now()->addDays(2)->startOfMinute()))->toBeTrue();
    });

    it('cannot be scheduled without a time', function () {
        $post = postIn(PostState::Approved);

        inWorkspace($this->workspace, function () use ($post) {
            expect(fn () => $post->schedule())->toThrow(IllegalPostTransition::class, 'without a time');
        });

        expect(fn () => DB::table('posts')->where('id', $post->id)->update(['state' => 'scheduled']))
            ->toThrow(QueryException::class);
    });

    it('stores the scheduled time in UTC', function (string $timezone, string $utc) {
        $workspace = Workspace::factory()->create(['timezone' => $timezone]);
        $post = inWorkspace($workspace, fn () => Post::factory()->approved()->create());

        inWorkspace($workspace, fn () => $post->schedule(new DateTimeImmutable('2026-07-15 12:00:00', new DateTimeZone($timezone))));

        expect(DB::table('posts')->where('id', $post->id)->value('scheduled_at'))->toBe($utc)
            ->and($workspace->toLocal($post->scheduled_at)->format('Y-m-d H:i'))->toBe('2026-07-15 12:00');
    })->with([
        ['Europe/Copenhagen', '2026-07-15 10:00:00'],
        ['Africa/Johannesburg', '2026-07-15 10:00:00'],
    ]);

    it('moves the time of a scheduled post without leaving the queue', function () {
        $post = postIn(PostState::Scheduled);
        $later = now()->addDays(3)->startOfMinute();

        inWorkspace($this->workspace, fn () => $post->schedule($later));

        expect($post->state)->toBe(PostState::Scheduled)
            ->and($post->scheduled_at->eq($later))->toBeTrue()
            ->and($post->approved_at)->not->toBeNull();
    });

    it('does not let the time of a scheduled post be saved directly', function () {
        $post = postIn(PostState::Scheduled);

        inWorkspace($this->workspace, function () use ($post) {
            expect(fn () => $post->update(['scheduled_at' => now()->addDays(3)]))->toThrow(IllegalPostTransition::class);
        });
    });

    it('lets a draft be planned for a time by plain assignment', function () {
        $post = postIn(PostState::Draft);

        inWorkspace($this->workspace, fn () => $post->update(['scheduled_at' => '2026-12-01 09:00:00']));

        expect($post->refresh()->scheduled_at->toDateTimeString())->toBe('2026-12-01 09:00:00')
            ->and($post->state)->toBe(PostState::Draft);
    });

    it('takes a post out of the queue and keeps its approval and time', function () {
        $post = postIn(PostState::Scheduled);
        $time = $post->scheduled_at;

        inWorkspace($this->workspace, fn () => $post->unschedule());

        expect($post->state)->toBe(PostState::Approved)
            ->and($post->approved_at)->not->toBeNull()
            ->and($post->scheduled_at->eq($time))->toBeTrue();
    });

    it('retries a failed post by scheduling it again with its approval', function () {
        $this->travelTo('2026-11-24 10:15:00');
        $post = postIn(PostState::Failed);
        $approvedBy = $post->approved_by;

        inWorkspace($this->workspace, fn () => $post->retry());

        expect($post->state)->toBe(PostState::Scheduled)
            ->and($post->scheduled_at->toDateTimeString())->toBe('2026-11-24 10:15:00')
            ->and($post->approved_by)->toBe($approvedBy);
    });
});

describe('claiming for publishing', function () {
    it('gives the claim to exactly one of two processes', function () {
        $post = postIn(PostState::Scheduled);
        $sameRowElsewhere = inWorkspace($this->workspace, fn () => Post::findOrFail($post->id));

        inWorkspace($this->workspace, function () use ($post, $sameRowElsewhere) {
            expect($post->claimForPublishing())->toBeTrue()
                ->and($sameRowElsewhere->state)->toBe(PostState::Scheduled)
                ->and($sameRowElsewhere->claimForPublishing())->toBeFalse()
                ->and($sameRowElsewhere->state)->toBe(PostState::Publishing)
                ->and($post->claimForPublishing())->toBeFalse();
        });
    });

    it('never claims a post that is not scheduled', function (PostState $state) {
        $post = postIn($state);

        expect(inWorkspace($this->workspace, fn () => $post->claimForPublishing()))->toBeFalse()
            ->and($post->state)->toBe($state);
    })->with([PostState::Draft, PostState::Approved, PostState::Publishing, PostState::Published, PostState::Failed, PostState::Rejected]);

    it('fails a transition when another process moved the post first', function () {
        $post = postIn(PostState::Scheduled);
        $stale = inWorkspace($this->workspace, fn () => Post::findOrFail($post->id));

        inWorkspace($this->workspace, function () use ($post, $stale) {
            $post->claimForPublishing();

            expect(fn () => $stale->returnToDraft())->toThrow(IllegalPostTransition::class, 'was no longer scheduled');
        });

        expect($post->refresh()->state)->toBe(PostState::Publishing)
            ->and($post->approved_at)->not->toBeNull();
    });
});

describe('finishing', function () {
    it('fails when a variant is not on its platform', function () {
        $post = inWorkspace($this->workspace, fn () => Post::factory()->withVariants(2)->publishing()->create());

        inWorkspace($this->workspace, function () use ($post) {
            $post->variants[0]->markPublished(new PublishResult('1_2', null, now()));
            $post->variants[1]->markFailed('The message is too long.');

            expect($post->finishPublishing())->toBe(PostState::Failed);
        });
    });

    it('fails a post that has no variants', function () {
        $post = inWorkspace($this->workspace, fn () => Post::factory()->publishing()->create());

        expect(inWorkspace($this->workspace, fn () => $post->finishPublishing()))->toBe(PostState::Failed);
    });
});

describe('due posts', function () {
    it('finds scheduled posts whose time has come, oldest first, in the current workspace only', function () {
        $this->travelTo('2026-11-24 12:00:30');
        $other = Workspace::factory()->create();

        [$now, $earlier] = inWorkspace($this->workspace, function () {
            $now = Post::factory()->scheduled(now()->startOfMinute())->create();
            $earlier = Post::factory()->scheduled(now()->subHour())->create();
            Post::factory()->scheduled(now()->addMinute())->create();
            Post::factory()->plannedFor(now()->subHour())->create();
            Post::factory()->plannedFor(now()->subHour())->approved()->create();
            Post::factory()->publishing()->create();
            Post::factory()->failed()->create();

            return [$now, $earlier];
        });
        inWorkspace($other, fn () => Post::factory()->due()->create());

        expect(inWorkspace($this->workspace, fn () => Post::query()->due()->pluck('id')->all()))->toBe([$earlier->id, $now->id])
            ->and(inWorkspace($other, fn () => Post::query()->due()->count()))->toBe(1)
            ->and(Post::query()->due()->count())->toBe(0)
            ->and(inWorkspace($this->workspace, fn () => Post::query()->due(now()->addHour())->count()))->toBe(3)
            ->and(inWorkspace($this->workspace, fn () => Post::query()->inState(PostState::Publishing, PostState::Failed)->count()))->toBe(2);
    });
});

it('keeps the approval time when the approving user is deleted', function () {
    $post = postIn(PostState::Draft);
    inWorkspace($this->workspace, fn () => $post->approve($this->editor));

    $this->editor->delete();

    expect($post->refresh()->approved_by)->toBeNull()
        ->and($post->approved_at)->not->toBeNull()
        ->and($post->state)->toBe(PostState::Approved);
});
