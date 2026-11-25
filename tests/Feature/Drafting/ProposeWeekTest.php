<?php

use App\Domain\Channels\ChannelStatus;
use App\Domain\Content\PostState;
use App\Domain\Drafting\Actions\ProposeWeek;
use App\Domain\Drafting\Console\RecoverStuckDraftRuns;
use App\Domain\Drafting\DraftBudget;
use App\Domain\Drafting\Exceptions\DraftGeneratorNotAvailable;
use App\Domain\Drafting\Exceptions\DraftingNotAllowed;
use App\Domain\Drafting\Exceptions\ProposalRefused;
use App\Domain\Drafting\GenerateDraftJob;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Drafting\Models\InstanceKeyRun;
use App\Domain\Drafting\Models\LlmCredential;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';
require_once __DIR__.'/../../Support/proposals.php';

beforeEach(function () {
    // A Wednesday. The week that is proposed starts on Monday 30 November.
    $this->travelTo('2026-11-25 10:00:00');
    Queue::fake();

    fakeChannelAdapter();
    $this->generator = fakeDraftGenerator();
    $this->workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->owner = userWithRole($this->workspace, Role::Owner);
    $this->channel = channelIn($this->workspace);
    $this->brief = briefIn($this->workspace);

    foreach ([1, 3, 5] as $weekday) {
        slotIn($this->workspace, $this->channel, $weekday, '09:00');
    }
});

/**
 * Expects the proposal to be refused for the given reason, with nothing
 * created, queued or asked of a provider.
 */
function expectRefused(string $reason, ?Closure $propose = null): ProposalRefused
{
    $test = test();
    $runsBefore = DB::table('draft_runs')->count();
    $refusal = null;

    try {
        $propose !== null ? $propose() : proposeWeek($test->workspace, $test->owner);
    } catch (ProposalRefused $caught) {
        $refusal = $caught;
    }

    expect($refusal)->not->toBeNull('The proposal was not refused.')
        ->and($refusal->reason)->toBe($reason)
        ->and(DB::table('draft_runs')->count())->toBe($runsBefore)
        ->and(DB::table('posts')->count())->toBe(0);

    Queue::assertNothingPushed();
    $test->generator->assertNothingGenerated();

    return $refusal;
}

describe('starting', function () {
    it('opens one pending run for each planned post and queues one job for each', function () {
        $craft = pillarIn($this->workspace, ['name' => 'Craft', 'weekly_target' => 2]);
        $idea = ideaIn($this->workspace, $craft);

        $runs = proposeWeek($this->workspace, $this->owner);

        expect($runs)->toHaveCount(3)
            ->and(DB::table('draft_runs')->count())->toBe(3)
            ->and(DB::table('draft_runs')->pluck('status')->unique()->all())->toBe(['pending'])
            ->and(DB::table('draft_runs')->pluck('pillar_id')->all())->toBe([$craft->id, $craft->id, null])
            ->and(DB::table('draft_runs')->pluck('idea_id')->all())->toBe([$idea->id, null, null]);

        $first = freshRun($runs[0]);
        $note = json_decode($first->request, true);

        expect($first)->toMatchObject([
            'workspace_id' => $this->workspace->id,
            'requested_by' => $this->owner->id,
            'brief_id' => $this->brief->id,
            'provider' => DRAFT_TEST_PROVIDER,
            'model' => DRAFT_TEST_MODEL,
            'prompt_version' => 'v1',
            'credential_scope' => 'instance',
        ])
            ->and($note)->toMatchArray([
                'week' => '2026-11-30',
                'planned_at' => '2026-11-30T08:00:00+00:00',
                'channel_ids' => [$this->channel->id],
                'attempt' => 1,
            ])
            ->and($note['proposal'])->toBeString()
            ->and(DB::table('draft_runs')->pluck('request')->map(fn ($request) => json_decode($request, true)['proposal'])->unique()->count())->toBe(1);

        Queue::assertPushed(GenerateDraftJob::class, 3);

        foreach ($runs as $run) {
            Queue::assertPushed(GenerateDraftJob::class, fn (GenerateDraftJob $job): bool => $job->runId === $run->id
                && $job->workspaceId === $this->workspace->id
                && $job->attempt === 1);
        }
    });

    it('creates no post and calls no provider itself', function () {
        proposeWeek($this->workspace, $this->owner);

        $this->generator->assertNothingGenerated();

        expect(DB::table('posts')->count())->toBe(0);
    });

    it('never stores the key or the prompt on a run', function () {
        proposeWeek($this->workspace, $this->owner);

        $stored = json_encode(DB::table('draft_runs')->get());

        expect($stored)->not->toContain(DRAFT_TEST_KEY)
            ->and($stored)->not->toContain($this->brief->body);
    });

    it('drafts no more posts than one proposal may', function () {
        config(['noongun.drafting.limits.posts_per_proposal' => 2]);

        expect(proposeWeek($this->workspace, $this->owner))->toHaveCount(2);

        Queue::assertPushed(GenerateDraftJob::class, 2);
    });

    it('marks a run failed at once when its job cannot be handed to the queue', function () {
        $this->mock(Dispatcher::class)
            ->shouldReceive('dispatch')
            ->andThrow(new RuntimeException('Connection refused: redis://user:secret@queue'));

        proposeWeek($this->workspace, $this->owner);

        expect(DB::table('draft_runs')->pluck('status')->all())->toBe(['failed', 'failed', 'failed'])
            ->and(DB::table('draft_runs')->pluck('failure_reason')->unique()->all())->toBe([ProposeWeek::NOT_QUEUED]);
    });
});

describe('who may start', function () {
    it('lets an owner and an editor start', function (Role $role) {
        expect(proposeWeek($this->workspace, userWithRole($this->workspace, $role)))->toHaveCount(3);
    })->with([Role::Owner, Role::Editor]);

    it('refuses a viewer, a member of another workspace and a user who is not saved', function (Closure $actor) {
        expect(fn () => proposeWeek($this->workspace, $actor($this)))->toThrow(DraftingNotAllowed::class);

        expect(DB::table('draft_runs')->count())->toBe(0);
        Queue::assertNothingPushed();
    })->with([
        'viewer' => [fn ($test) => userWithRole($test->workspace, Role::Viewer)],
        'owner elsewhere' => [fn () => userWithRole(Workspace::factory()->create(), Role::Owner)],
        'unsaved' => [fn () => User::factory()->make()],
    ]);
});

describe('what must be in place first', function () {
    it('refuses without a brief', function () {
        DB::table('briefs')->delete();

        expect(expectRefused(ProposalRefused::BRIEF_MISSING)->getMessage())->toContain('no brief');
    });

    it('refuses without a channel that can publish', function () {
        DB::table('channels')->update(['status' => ChannelStatus::NeedsReconnect->value]);

        expect(expectRefused(ProposalRefused::NO_CHANNEL)->getMessage())->toContain('No channel is connected that can publish');
    });

    it('refuses when the channels that can publish have no slot', function () {
        DB::table('slots')->delete();
        slotIn($this->workspace, channelIn($this->workspace, ['status' => ChannelStatus::Disconnected]), 1, '09:00');

        expect(expectRefused(ProposalRefused::NO_SLOT)->getMessage())->toContain('No slot is set');
    });

    it('refuses without a key, in words a person can act on', function () {
        config(['noongun.drafting.providers.'.DRAFT_TEST_PROVIDER.'.api_key' => null]);

        expect(expectRefused(ProposalRefused::NO_KEY)->getMessage())->toContain('No API key is set');
    });

    it('refuses when drafting is not set up, with a fixed sentence for members and the detail in the log', function (array $config, string $detail) {
        config($config);
        Log::spy();

        $message = expectRefused(ProposalRefused::NOT_CONFIGURED)->getMessage();

        expect($message)->toBe(DraftGeneratorNotAvailable::FOR_MEMBERS);

        foreach (['config/', 'NOONGUN_', 'App\\', '[', 'drafting.providers'] as $operatorOnly) {
            expect($message)->not->toContain($operatorOnly);
        }

        Log::shouldHaveReceived('warning')->withArgs(fn (string $line, array $context): bool => str_contains($context['detail'] ?? '', $detail)
            && $context['workspace_id'] === $this->workspace->id);
    })->with([
        'an unknown provider' => [['noongun.drafting.provider' => 'nobody'], 'No draft generator is configured for provider [nobody]'],
        'no provider' => [['noongun.drafting.provider' => ''], 'NOONGUN_DRAFT_PROVIDER'],
        'no model' => [['noongun.drafting.providers.'.DRAFT_TEST_PROVIDER.'.model' => null], 'No model is configured'],
    ]);

    it('refuses a week in which every slot time has passed or has a post', function () {
        // Friday at nine in Copenhagen: the last of the three slots is now.
        $this->travelTo('2026-12-04 08:00:00');

        slotIn($this->workspace, $this->channel, 6, '09:00');
        postInState($this->workspace, PostState::Draft, [$this->channel], new DateTimeImmutable('2026-12-05 08:00:00'));

        expect(fn () => proposeWeek($this->workspace, $this->owner))->toThrow(ProposalRefused::class, 'nothing to draft');
        expect(DB::table('draft_runs')->count())->toBe(0);
        Queue::assertNothingPushed();
        $this->generator->assertNothingGenerated();
    });

    it('refuses a plan that changed after the person saw it', function () {
        $seen = planFor($this->workspace, '2026-11-30')->fingerprint();
        slotIn($this->workspace, $this->channel, 2, '09:00');

        expect(expectRefused(
            ProposalRefused::PLAN_CHANGED,
            fn () => proposeWeek($this->workspace, $this->owner, '2026-11-30', $seen),
        )->getMessage())->toContain('changed after you opened it');
    });

    it('refuses a fingerprint that was made up', function () {
        expectRefused(ProposalRefused::PLAN_CHANGED, fn () => proposeWeek($this->workspace, $this->owner, '2026-11-30', 'made-up'));
    });
});

describe('proposing the same week again', function () {
    beforeEach(function () {
        proposeWeek($this->workspace, $this->owner);
        runQueuedDraftJobs();
        Queue::fake();

        $this->monday = DB::table('posts')->orderBy('scheduled_at')->value('id');
    });

    it('repeats nothing while the drafts are where they were planned', function () {
        $plan = planFor($this->workspace, '2026-11-30');

        expect($plan->posts)->toBe([])->and($plan->taken)->toBe(3);
    });

    it('does not open a slot again because its draft was moved to another time', function (?string $movedTo) {
        DB::table('posts')->where('id', $this->monday)->update(['scheduled_at' => $movedTo]);

        $plan = planFor($this->workspace, '2026-11-30');

        expect($plan->posts)->toBe([])->and($plan->taken)->toBe(3);
        expect(fn () => proposeWeek($this->workspace, $this->owner))->toThrow(ProposalRefused::class, 'nothing to draft');
    })->with([
        'later the same day' => ['2026-11-30 15:00:00'],
        'another day of the week' => ['2026-12-03 12:00:00'],
        'another week' => ['2026-12-10 08:00:00'],
        'no time at all' => [null],
    ]);

    it('still treats the slot as answered when the moved draft was approved, scheduled or published', function (PostState $state) {
        DB::table('posts')->where('id', $this->monday)->update([
            'state' => $state->value,
            'scheduled_at' => '2026-11-30 15:00:00',
            'approved_at' => now(),
        ]);

        expect(planFor($this->workspace, '2026-11-30')->posts)->toBe([]);
    })->with([PostState::Approved, PostState::Scheduled, PostState::Published, PostState::Failed]);

    it('opens the slot again when its draft was rejected or deleted', function (Closure $remove) {
        $remove($this->monday);

        $plan = planFor($this->workspace, '2026-11-30');

        expect(count($plan->posts))->toBe(1)
            ->and($plan->posts[0]->at->toDateTimeString())->toBe('2026-11-30 08:00:00')
            ->and($plan->taken)->toBe(2);
    })->with([
        'rejected' => [fn (int $post) => DB::table('posts')->where('id', $post)->update(['state' => 'rejected'])],
        'deleted' => [fn (int $post) => DB::table('posts')->where('id', $post)->delete()],
    ]);

    it('does not hold the slot in another week', function () {
        expect(count(planFor($this->workspace, '2026-12-07')->posts))->toBe(3);
    });

    it('does not hold a slot for a run that failed', function () {
        DB::table('posts')->where('id', $this->monday)->delete();
        DB::table('draft_runs')->orderBy('id')->limit(1)->update(['status' => 'failed', 'failure_reason' => 'The provider refused the request.']);

        expect(count(planFor($this->workspace, '2026-11-30')->posts))->toBe(1);
    });
});

describe('limits', function () {
    it('refuses a second proposal while the first is pending or running', function (Closure $advance) {
        $first = proposeWeek($this->workspace, $this->owner);
        $advance($this, $first);

        Queue::fake();
        slotIn($this->workspace, $this->channel, 2, '09:00');

        $runs = DB::table('draft_runs')->count();

        expect(fn () => proposeWeek($this->workspace, $this->owner))->toThrow(ProposalRefused::class, 'still being generated');
        expect(DB::table('draft_runs')->count())->toBe($runs);
        Queue::assertNothingPushed();
    })->with([
        'all pending' => [fn () => null],
        'one running, the rest done' => [function ($test, array $runs) {
            inWorkspace($test->workspace, fn () => $runs[0]->markRunning());
            runDraftJob(new GenerateDraftJob($test->workspace->id, $runs[1]->id));
            runDraftJob(new GenerateDraftJob($test->workspace->id, $runs[2]->id));
        }],
    ]);

    it('lets the second of two simultaneous requests for the same plan through to nothing', function () {
        // Both requests were made from the same page, so both carry the
        // fingerprint of the same plan.
        $seen = planFor($this->workspace, '2026-11-30')->fingerprint();

        proposeWeek($this->workspace, $this->owner, '2026-11-30', $seen);

        expect(fn () => proposeWeek($this->workspace, $this->owner, '2026-11-30', $seen))->toThrow(ProposalRefused::class);
        expect(DB::table('draft_runs')->count())->toBe(3);
        Queue::assertPushed(GenerateDraftJob::class, 3);
    });

    it('locks the workspace before it reads or writes a draft run, so two requests cannot both pass the checks', function () {
        // The page worked the plan out earlier; only the start is watched.
        $seen = planFor($this->workspace, '2026-11-30')->fingerprint();

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = strtolower($query->sql);
        });

        proposeWeek($this->workspace, $this->owner, '2026-11-30', $seen);

        $position = function (Closure $matches) use (&$statements): ?int {
            foreach ($statements as $index => $sql) {
                if ($matches($sql)) {
                    return $index;
                }
            }

            return null;
        };

        $lock = $position(fn (string $sql) => str_contains($sql, '"workspaces"') && str_contains($sql, 'for update'));
        $firstRead = $position(fn (string $sql) => str_contains($sql, '"draft_runs"'));
        $firstWrite = $position(fn (string $sql) => str_starts_with($sql, 'insert into "draft_runs"'));

        expect($lock)->not->toBeNull()
            ->and($firstRead)->toBeGreaterThan($lock)
            ->and($firstWrite)->toBeGreaterThan($firstRead);
    });

    it('allows a new proposal once the earlier one is settled', function () {
        proposeWeek($this->workspace, $this->owner);
        runQueuedDraftJobs();

        slotIn($this->workspace, $this->channel, 2, '09:00');

        expect(proposeWeek($this->workspace, $this->owner))->toHaveCount(1)
            ->and(DB::table('draft_runs')->count())->toBe(4);
    });

    it('refuses a proposal that would take the workspace over its draft runs for 24 hours', function () {
        config(['noongun.drafting.limits.runs_per_day' => 5]);

        inWorkspace($this->workspace, fn () => DraftRun::factory()->count(3)->succeeded()->create());

        $refusal = expectRefused(ProposalRefused::DAILY_LIMIT);

        expect($refusal->getMessage())->toContain('can draft 5 posts in 24 hours')
            ->and($refusal->getMessage())->toContain('has used 3')
            ->and($refusal->getMessage())->toContain('needs 3');
    });

    it('counts a run that reached a provider or may still do so, and no other', function (Closure $make, bool $counts) {
        // Three posts are planned and three runs are allowed: one more that counts is one too many.
        config(['noongun.drafting.limits.runs_per_day' => 3]);

        $run = inWorkspace($this->workspace, fn () => $make()->create());

        if ($counts) {
            expectRefused(ProposalRefused::DAILY_LIMIT, fn () => proposeWeek($this->workspace, $this->owner));

            return;
        }

        // An open run would stand in the way for another reason.
        expect(DB::table('draft_runs')->where('id', $run->id)->value('status'))->toBe('failed')
            ->and(proposeWeek($this->workspace, $this->owner))->toHaveCount(3);
    })->with([
        'written' => [fn () => DraftRun::factory()->succeeded(), true],
        'failed after the provider answered' => [fn () => DraftRun::factory()->failed()->afterCreating(
            fn (DraftRun $run) => DB::table('draft_runs')->where('id', $run->id)->update(['attempts' => 2, 'input_tokens' => 2400, 'output_tokens' => 600]),
        ), true],
        'could not be queued' => [fn () => DraftRun::factory()->failed(ProposeWeek::NOT_QUEUED), false],
        'never picked up' => [fn () => DraftRun::factory()->failed(RecoverStuckDraftRuns::NEVER_STARTED), false],
        'turned away for a wrong key' => [fn () => DraftRun::factory()->failed('The provider did not accept the API key.'), false],
        'turned away for an unknown model' => [fn () => DraftRun::factory()->failed('The provider does not know this model.'), false],
    ]);

    it('counts a run that is still open towards the day', function (string $status) {
        config(['noongun.drafting.limits.runs_per_day' => 3]);

        inWorkspace($this->workspace, fn () => $status === 'running' ? DraftRun::factory()->running()->create() : DraftRun::factory()->create());

        expect(inWorkspace($this->workspace, fn () => app(DraftBudget::class)->usedByWorkspace()))->toBe(1);
    })->with(['pending', 'running']);

    it('does not lock a person out for a day who tried a wrong key three times', function () {
        config(['noongun.drafting.limits.runs_per_day' => 5]);

        inWorkspace($this->workspace, fn () => DraftRun::factory()->count(9)->failed('The provider did not accept the API key.')->create());

        expect(proposeWeek($this->workspace, $this->owner))->toHaveCount(3);
    });

    describe('on the key of the instance', function () {
        beforeEach(function () {
            config(['noongun.drafting.limits.instance_runs_per_day' => 5]);

            $this->other = Workspace::factory()->create();
        });

        it('refuses a proposal that would take all workspaces together over the limit of that key', function () {
            inWorkspace($this->other, fn () => DraftRun::factory()->count(3)->succeeded()->create());

            $message = expectRefused(ProposalRefused::INSTANCE_LIMIT)->getMessage();

            expect($message)->toContain('shared by all its workspaces and can draft 5 posts in 24 hours')
                ->and($message)->toContain('3 are used and this proposal needs 3')
                ->and($message)->toContain('NOONGUN_DRAFT_MAX_INSTANCE_RUNS_PER_DAY');
        });

        it('allows a proposal that exactly reaches the limit', function () {
            inWorkspace($this->other, fn () => DraftRun::factory()->count(2)->succeeded()->create());

            expect(proposeWeek($this->workspace, $this->owner))->toHaveCount(3);
        });

        it('counts open runs and runs that spent, of any workspace, in the last 24 hours and on the instance key only', function () {
            inWorkspace($this->other, function () {
                DraftRun::factory()->create();
                DraftRun::factory()->running()->create();
                DraftRun::factory()->succeeded()->create();
                DraftRun::factory()->failed('The provider did not accept the API key.')->create();
                $old = DraftRun::factory()->succeeded()->create();
                $theirKey = DraftRun::factory()->succeeded()->create();

                DB::table('draft_runs')->where('id', $old->id)->update(['created_at' => now()->subHours(25)]);
                DB::table('draft_runs')->where('id', $theirKey->id)->update(['credential_scope' => 'workspace']);
            });

            expect(app(DraftBudget::class)->usedOnInstanceKey())->toBe(3)
                ->and(inWorkspace($this->workspace, fn () => app(DraftBudget::class)->usedByWorkspace()))->toBe(0);
        });

        it('does not hold a workspace that pays with a key of its own', function () {
            inWorkspace($this->other, fn () => DraftRun::factory()->count(5)->succeeded()->create());
            inWorkspace($this->workspace, fn () => LlmCredential::store(DRAFT_TEST_PROVIDER, 'a-workspace-llm-key'));

            $runs = proposeWeek($this->workspace, $this->owner);

            expect($runs)->toHaveCount(3)
                ->and(freshRun($runs[0])->credential_scope)->toBe('workspace');
        });

        it('gives numbers only: the model that counts cannot write a run', function () {
            $run = draftRunIn($this->workspace, $this->owner);

            expect(fn () => InstanceKeyRun::query()->findOrFail($run->id)->delete())->toThrow(LogicException::class)
                ->and(fn () => InstanceKeyRun::query()->findOrFail($run->id)->forceFill(['model' => 'other'])->save())->toThrow(LogicException::class)
                ->and(DB::table('draft_runs')->where('id', $run->id)->value('model'))->toBe(DRAFT_TEST_MODEL);
        });
    });

    it('names the setting to a person who runs the instance, and not to a tenant', function (string $mode, bool $named) {
        config(['noongun.mode' => $mode, 'noongun.drafting.limits.runs_per_day' => 2]);

        $message = expectRefused(ProposalRefused::DAILY_LIMIT)->getMessage();

        expect(str_contains($message, 'NOONGUN_DRAFT_MAX_RUNS_PER_DAY'))->toBe($named)
            ->and($message)->toContain('Try again later')
            ->and($message)->not->toContain('ask the operator');
    })->with([['self_hosted', true], ['saas', false]]);

    it('counts only the runs of the last 24 hours and only those of the workspace', function () {
        config(['noongun.drafting.limits.runs_per_day' => 5]);

        inWorkspace($this->workspace, fn () => DraftRun::factory()->count(3)->succeeded()->create());
        DB::table('draft_runs')->update(['created_at' => now()->subHours(25)]);

        $other = Workspace::factory()->create();
        inWorkspace($other, fn () => DraftRun::factory()->count(5)->succeeded()->create());

        expect(proposeWeek($this->workspace, $this->owner))->toHaveCount(3);
    });

    it('allows a proposal that exactly reaches the limit', function () {
        config(['noongun.drafting.limits.runs_per_day' => 3]);

        expect(proposeWeek($this->workspace, $this->owner))->toHaveCount(3);
    });

    it('does not let a pending run of another workspace stand in the way', function () {
        $other = Workspace::factory()->create();
        draftRunIn($other);

        expect(proposeWeek($this->workspace, $this->owner))->toHaveCount(3);
    });
});
