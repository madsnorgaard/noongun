<?php

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Models\Channel;
use App\Domain\Drafting\Console\RecoverStuckDraftRuns;
use App\Domain\Drafting\CredentialScope;
use App\Domain\Drafting\DraftGenerators;
use App\Domain\Drafting\GenerateDraftJob;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Drafting\Models\LlmCredential;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';
require_once __DIR__.'/../../Support/proposals.php';

beforeEach(function () {
    $this->travelTo('2026-11-25 10:00:00');
    Queue::fake();

    fakeChannelAdapter();
    $this->generator = fakeDraftGenerator();
    $this->workspace = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
    $this->owner = userWithRole($this->workspace, Role::Owner);
    $this->channel = channelIn($this->workspace);
    $this->brief = briefIn($this->workspace);
});

/**
 * A pending run as ProposeWeek opens it, for the test's channel.
 *
 * @param  array<string, mixed>  $note
 */
function proposedRun(array $note = []): DraftRun
{
    $test = test();

    return draftRunIn($test->workspace, $test->owner, request: [
        'proposal' => 'proposal-1',
        'week' => '2026-11-30',
        'planned_at' => '2026-11-30T07:00:00+00:00',
        'channel_ids' => [$test->channel->id],
        'slot_ids' => [],
        'attempt' => 1,
        ...$note,
    ]);
}

/**
 * @return list<object>
 */
function retriesOf(DraftRun $run): array
{
    return DB::table('draft_runs')->where('request->retry_of', $run->id)->orderBy('id')->get()->all();
}

describe('one run', function () {
    it('generates the draft for the planned time and records it on the run', function () {
        $run = proposedRun();

        runDraftJob(new GenerateDraftJob($this->workspace->id, $run->id));

        $post = DB::table('posts')->sole();

        expect($post->state)->toBe('draft')
            ->and($post->approved_at)->toBeNull()
            ->and($post->scheduled_at)->toBe('2026-11-30 07:00:00')
            ->and($post->draft_run_id)->toBe($run->id)
            ->and(freshRun($run)->status)->toBe('succeeded')
            ->and(DB::table('post_variants')->where('post_id', $post->id)->pluck('channel_id')->all())->toBe([$this->channel->id]);

        $this->generator->assertGeneratedTimes(1);
        Queue::assertNothingPushed();
    });

    it('asks from the brief version, the pillar and the idea the run was opened with', function () {
        $pillar = pillarIn($this->workspace, ['name' => 'Craft']);
        $idea = ideaIn($this->workspace, $pillar, ['title' => 'How we roast']);
        $run = draftRunIn($this->workspace, $this->owner, $pillar, $idea, [
            'planned_at' => '2026-11-30T07:00:00+00:00',
            'channel_ids' => [$this->channel->id],
        ]);

        // A later version of the brief does not change what was asked for.
        briefIn($this->workspace, ['title' => 'A newer brief']);

        runDraftJob(new GenerateDraftJob($this->workspace->id, $run->id));

        $request = $this->generator->lastRequest();

        expect($request->briefTitle)->toBe($this->brief->title)
            ->and($request->pillarName)->toBe('Craft')
            ->and($request->ideaTitle)->toBe('How we roast')
            ->and(DB::table('posts')->sole()->pillar_id)->toBe($pillar->id);
    });

    it('carries only ids, never a key, a context or a model', function () {
        $job = new GenerateDraftJob($this->workspace->id, proposedRun()->id, 2);
        $payload = serialize($job);

        $given = [];

        foreach ((new ReflectionClass(GenerateDraftJob::class))->getConstructor()->getParameters() as $parameter) {
            $given[$parameter->getName()] = (string) $parameter->getType();
        }

        // Everything else on the job is the framework's own (queue, delay and so on).
        $own = array_filter(
            (new ReflectionClass(GenerateDraftJob::class))->getProperties(),
            fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === GenerateDraftJob::class
                && ! in_array($property->getName(), ['tries', 'timeout', 'failOnTimeout'], true)
                && ! (new ReflectionClass(GenerateDraftJob::class))->getTraits()['Illuminate\Foundation\Queue\Queueable']->hasProperty($property->getName()),
        );

        expect($given)->toBe(['workspaceId' => 'int', 'runId' => 'int', 'attempt' => 'int'])
            ->and(array_values(array_map(fn (ReflectionProperty $property): string => $property->getName(), $own)))->toBe(['workspaceId', 'runId', 'attempt'])
            ->and($job->workspaceId)->toBe($this->workspace->id)
            ->and($job->attempt)->toBe(2)
            ->and($payload)->not->toContain(DRAFT_TEST_KEY)
            ->and($payload)->not->toContain('LlmKey')
            ->and($payload)->not->toContain('DraftingContext')
            ->and($payload)->not->toContain(DRAFT_TEST_MODEL);
    });

    it('is never run again by the queue, and has a time limit from the configuration', function () {
        config(['noongun.drafting.timeout' => 40]);

        $job = new GenerateDraftJob(1, 1);

        expect($job->tries)->toBe(1)
            ->and($job->timeout)->toBe(95)
            ->and($job->failOnTimeout)->toBeTrue();
    });
});

describe('replay and double dispatch', function () {
    it('generates once when the same job is run twice', function () {
        $run = proposedRun();
        $job = new GenerateDraftJob($this->workspace->id, $run->id);

        runDraftJob(clone $job);
        runDraftJob(clone $job);
        runDraftJob(clone $job);

        $this->generator->assertGeneratedTimes(1);

        expect(DB::table('posts')->count())->toBe(1);
    });

    it('generates once when two jobs were queued for one run', function () {
        $run = proposedRun();

        runDraftJob(new GenerateDraftJob($this->workspace->id, $run->id));
        runDraftJob(new GenerateDraftJob($this->workspace->id, $run->id, 2));

        $this->generator->assertGeneratedTimes(1);

        expect(DB::table('posts')->count())->toBe(1)
            ->and(DB::table('draft_runs')->count())->toBe(1);
    });

    it('does nothing for a run that another worker is running or that is settled', function (string $status) {
        $run = inWorkspace($this->workspace, fn () => DraftRun::factory()->{$status}()->create([
            'requested_by' => $this->owner->id,
            'request' => ['channel_ids' => [$this->channel->id]],
        ]));
        $before = freshRun($run);

        runDraftJob(new GenerateDraftJob($this->workspace->id, $run->id));

        $this->generator->assertNothingGenerated();

        expect(freshRun($run))->toEqual($before)
            ->and(DB::table('posts')->count())->toBe(0);
    })->with(['running', 'succeeded', 'failed']);

    it('does not open a second retry when a failed job is run again', function () {
        $run = proposedRun();
        $this->generator->failRetryable(2);

        runDraftJob(new GenerateDraftJob($this->workspace->id, $run->id));
        runDraftJob(new GenerateDraftJob($this->workspace->id, $run->id));

        $this->generator->assertGeneratedTimes(1);

        expect(retriesOf($run))->toHaveCount(1);
        Queue::assertPushed(GenerateDraftJob::class, 1);
    });

    it('does nothing for a run that is not in the workspace the job names', function () {
        $other = Workspace::factory()->create();
        $run = proposedRun();

        runDraftJob(new GenerateDraftJob($other->id, $run->id));
        runDraftJob(new GenerateDraftJob(999999, $run->id));

        $this->generator->assertNothingGenerated();

        expect(freshRun($run)->status)->toBe('pending');
    });
});

describe('failures', function () {
    it('ends the run as failed with the reason and does not try again after a permanent failure', function () {
        $run = proposedRun();
        $this->generator->failPermanently(message: 'The provider refused the request.');

        runDraftJob(new GenerateDraftJob($this->workspace->id, $run->id));

        expect(freshRun($run))->toMatchObject(['status' => 'failed', 'failure_reason' => 'The provider refused the request.'])
            ->and(DB::table('draft_runs')->count())->toBe(1)
            ->and(DB::table('posts')->count())->toBe(0);

        Queue::assertNothingPushed();
    });

    it('ends the run as failed after two invalid answers, with what both cost, and does not try again', function () {
        $run = proposedRun();
        $this->generator->failInvalid();

        runDraftJob(new GenerateDraftJob($this->workspace->id, $run->id));

        $stored = freshRun($run);

        expect($stored->status)->toBe('failed')
            ->and($stored->failure_reason)->toContain('could not be used')
            ->and($stored->attempts)->toBe(2)
            ->and($stored->input_tokens)->toBe(2400)
            ->and($stored->cost_estimate)->not->toBeNull()
            ->and(DB::table('draft_runs')->count())->toBe(1)
            ->and(DB::table('posts')->count())->toBe(0);

        Queue::assertNothingPushed();
    });

    it('opens a new run and queues it after the backoff when the failure is worth trying again', function () {
        config(['noongun.drafting.limits.backoff' => [30, 120]]);

        $pillar = pillarIn($this->workspace);
        $run = draftRunIn($this->workspace, $this->owner, $pillar, request: [
            'proposal' => 'proposal-1',
            'planned_at' => '2026-11-30T07:00:00+00:00',
            'channel_ids' => [$this->channel->id],
            'attempt' => 1,
        ]);
        $this->generator->failRetryable(message: 'The provider is busy.');

        runDraftJob(new GenerateDraftJob($this->workspace->id, $run->id));

        // The failed run is not reopened: it keeps its reason.
        expect(freshRun($run))->toMatchObject(['status' => 'failed', 'failure_reason' => 'The provider is busy.']);

        $next = retriesOf($run)[0];
        $note = json_decode($next->request, true);

        expect($next->status)->toBe('pending')
            ->and($next->pillar_id)->toBe($pillar->id)
            ->and($next->brief_id)->toBe($this->brief->id)
            ->and($next->requested_by)->toBe($this->owner->id)
            ->and($note)->toMatchArray(['proposal' => 'proposal-1', 'attempt' => 2, 'retry_of' => $run->id, 'planned_at' => '2026-11-30T07:00:00+00:00']);

        Queue::assertPushed(GenerateDraftJob::class, fn (GenerateDraftJob $job): bool => $job->runId === $next->id
            && $job->workspaceId === $this->workspace->id
            && $job->attempt === 2
            && $job->delay === 30);

        // The retry succeeds and gives the one draft.
        runDraftJob(new GenerateDraftJob($this->workspace->id, $next->id, 2));

        expect(DB::table('posts')->sole()->draft_run_id)->toBe($next->id)
            ->and(DB::table('draft_runs')->where('id', $next->id)->value('status'))->toBe('succeeded');
    });

    it('waits longer before each retry and stops at the configured number of attempts', function () {
        config(['noongun.drafting.limits.backoff' => [30, 120], 'noongun.drafting.limits.max_attempts' => 3]);

        $first = proposedRun();
        $this->generator->failRetryable(5);

        runDraftJob(new GenerateDraftJob($this->workspace->id, $first->id));
        $second = retriesOf($first)[0];
        runDraftJob(new GenerateDraftJob($this->workspace->id, $second->id, 2));
        $third = DB::table('draft_runs')->where('request->retry_of', $second->id)->sole();
        runDraftJob(new GenerateDraftJob($this->workspace->id, $third->id, 3));

        $this->generator->assertGeneratedTimes(3);

        expect(DB::table('draft_runs')->pluck('status')->all())->toBe(['failed', 'failed', 'failed'])
            ->and(DB::table('posts')->count())->toBe(0);

        Queue::assertPushed(GenerateDraftJob::class, 2);
        Queue::assertPushed(GenerateDraftJob::class, fn (GenerateDraftJob $job): bool => $job->attempt === 3 && $job->delay === 120);
    });

    it('does not try again when one attempt is all that is configured', function () {
        config(['noongun.drafting.limits.max_attempts' => 1]);

        $run = proposedRun();
        $this->generator->failRetryable();

        runDraftJob(new GenerateDraftJob($this->workspace->id, $run->id));

        expect(DB::table('draft_runs')->count())->toBe(1);
        Queue::assertNothingPushed();
    });

    it('does not try again once the workspace has used its draft runs for the day', function () {
        config(['noongun.drafting.limits.runs_per_day' => 1]);

        inWorkspace($this->workspace, fn () => DraftRun::factory()->succeeded()->create());
        $run = proposedRun();
        $this->generator->failRetryable();

        runDraftJob(new GenerateDraftJob($this->workspace->id, $run->id));

        expect(DB::table('draft_runs')->count())->toBe(2)
            ->and(freshRun($run)->status)->toBe('failed');
        Queue::assertNothingPushed();
    });

    it('does try again when the only run of the day is the one that failed without spending', function () {
        config(['noongun.drafting.limits.runs_per_day' => 1]);

        $run = proposedRun();
        $this->generator->failRetryable();

        runDraftJob(new GenerateDraftJob($this->workspace->id, $run->id));

        expect(retriesOf($run))->toHaveCount(1);
        Queue::assertPushed(GenerateDraftJob::class, 1);
    });

    it('fails the run with words a person can act on when the key is gone, without calling a provider', function () {
        $run = proposedRun();
        config(['noongun.drafting.providers.'.DRAFT_TEST_PROVIDER.'.api_key' => null]);

        runDraftJob(new GenerateDraftJob($this->workspace->id, $run->id));

        $this->generator->assertNothingGenerated();

        expect(freshRun($run)->status)->toBe('failed')
            ->and(freshRun($run)->failure_reason)->toContain('No API key is set');
        Queue::assertNothingPushed();
    });

    it('spends nothing when who pays changed after the run was opened', function (Closure $change, string $what) {
        $run = $change($this);

        runDraftJob(new GenerateDraftJob($this->workspace->id, $run->id));

        $this->generator->assertNothingGenerated();

        $stored = freshRun($run);

        expect($stored->status)->toBe('failed')
            ->and($stored->failure_reason)->toContain('The drafting settings changed after this draft was asked for')
            ->and($stored->failure_reason)->toContain($what)
            ->and($stored->failure_reason)->toContain('nothing was spent')
            ->and($stored->input_tokens)->toBeNull()
            ->and(DB::table('draft_runs')->count())->toBe(1)
            ->and(DB::table('posts')->count())->toBe(0);
        Queue::assertNothingPushed();
    })->with([
        'the workspace key was removed, so the instance would pay' => [function ($test) {
            inWorkspace($test->workspace, fn () => LlmCredential::store(DRAFT_TEST_PROVIDER, 'a-workspace-llm-key'));
            $run = inWorkspace($test->workspace, fn () => DraftRun::start(
                $test->owner,
                app(DraftGenerators::class)->contextFor($test->workspace),
                ['channel_ids' => [$test->channel->id]],
                $test->brief,
            ));

            expect($run->credential_scope)->toBe(CredentialScope::Workspace);
            DB::table('llm_credentials')->where('workspace_id', $test->workspace->id)->delete();

            return $run;
        }, 'the API key that pays for it'],
        'a workspace key was added, so the workspace would pay' => [function ($test) {
            $run = proposedRun();
            inWorkspace($test->workspace, fn () => LlmCredential::store(DRAFT_TEST_PROVIDER, 'a-workspace-llm-key'));

            return $run;
        }, 'the API key that pays for it'],
        'the model was changed' => [function () {
            $run = proposedRun();
            config(['noongun.drafting.providers.'.DRAFT_TEST_PROVIDER.'.model' => 'a-dearer-model']);

            return $run;
        }, 'the model'],
        'the prompt version was changed' => [function () {
            $run = proposedRun();
            config(['noongun.drafting.prompt_version' => 'v2']);

            return $run;
        }, 'the prompt version'],
    ]);

    it('fails the run without calling a provider when the person who asked may no longer write', function () {
        $run = proposedRun();
        DB::table('memberships')->where('user_id', $this->owner->id)->update(['role' => Role::Viewer->value]);

        runDraftJob(new GenerateDraftJob($this->workspace->id, $run->id));

        $this->generator->assertNothingGenerated();

        expect(freshRun($run)->status)->toBe('failed')
            ->and(DB::table('posts')->count())->toBe(0);
    });

    it('fails the run without calling a provider when what it needs is gone', function (Closure $remove, string $reason) {
        $run = proposedRun();
        $remove($this);

        runDraftJob(new GenerateDraftJob($this->workspace->id, $run->id));

        $this->generator->assertNothingGenerated();

        expect(freshRun($run))->toMatchObject(['status' => 'failed', 'failure_reason' => $reason]);
    })->with([
        'the requester' => [fn ($test) => DB::table('users')->where('id', $test->owner->id)->delete(), GenerateDraftJob::REQUESTER_GONE],
        'the brief' => [fn ($test) => DB::table('briefs')->where('id', $test->brief->id)->delete(), GenerateDraftJob::BRIEF_GONE],
        'the channel' => [fn ($test) => DB::table('channels')->where('id', $test->channel->id)->update(['status' => ChannelStatus::NeedsReconnect->value]), GenerateDraftJob::CHANNELS_GONE],
        'the platform' => [fn ($test) => DB::table('channels')->where('id', $test->channel->id)->update(['platform' => 'unknown-platform']), GenerateDraftJob::CHANNELS_GONE],
    ]);

    it('drafts for the channels that can still publish when one of them cannot', function () {
        $second = channelIn($this->workspace);
        $run = proposedRun(['channel_ids' => [$this->channel->id, $second->id]]);
        inWorkspace($this->workspace, fn () => Channel::query()->findOrFail($second->id)->markNeedsReconnect());

        runDraftJob(new GenerateDraftJob($this->workspace->id, $run->id));

        expect(DB::table('post_variants')->pluck('channel_id')->all())->toBe([$this->channel->id])
            ->and(freshRun($run)->status)->toBe('succeeded');
    });

    it('fails the run and reports the error when something unexpected happens', function () {
        $run = proposedRun();
        $this->generator->returnsOutput(fn () => throw new RuntimeException('secret detail sk-ant-abcdefghijklmnop'));

        expect(fn () => runDraftJob(new GenerateDraftJob($this->workspace->id, $run->id)))->toThrow(RuntimeException::class);

        expect(freshRun($run)->status)->toBe('failed')
            ->and(freshRun($run)->failure_reason)->toBe('The draft could not be generated because of an unexpected error.')
            ->and(DB::table('posts')->count())->toBe(0);
        Queue::assertNothingPushed();
    });
});

describe('a job that died', function () {
    it('closes its run with a readable reason when the queue gives up on the job', function () {
        $run = proposedRun();
        inWorkspace($this->workspace, fn () => $run->markRunning());

        (new GenerateDraftJob($this->workspace->id, $run->id))->failed(new RuntimeException('Timed out.'));

        expect(freshRun($run))->toMatchObject(['status' => 'failed', 'failure_reason' => GenerateDraftJob::INTERRUPTED]);
        $this->generator->assertNothingGenerated();
    });

    it('leaves the run to the first delivery when the queue delivered the job twice', function () {
        $run = proposedRun();
        inWorkspace($this->workspace, fn () => $run->markRunning());

        (new GenerateDraftJob($this->workspace->id, $run->id))->failed(new MaxAttemptsExceededException('Attempted too many times.'));

        expect(freshRun($run)->status)->toBe('running');
    });

    it('does not touch a run that was settled', function () {
        $run = proposedRun();
        runDraftJob(new GenerateDraftJob($this->workspace->id, $run->id));

        (new GenerateDraftJob($this->workspace->id, $run->id))->failed(new RuntimeException('Late.'));

        expect(freshRun($run)->status)->toBe('succeeded')
            ->and(freshRun($run)->failure_reason)->toBeNull();
    });
});

describe('noongun:recover-stuck-draft-runs', function () {
    beforeEach(function () {
        config([
            'noongun.drafting.timeout' => 20,
            'noongun.drafting.limits.stuck_after_minutes' => 15,
            'noongun.drafting.limits.backoff' => [30, 120],
        ]);
    });

    it('marks a run failed that has been running for longer than a worker may take', function () {
        $stuck = proposedRun();
        inWorkspace($this->workspace, fn () => $stuck->markRunning());

        $this->travel(14)->minutes();
        $this->artisan('noongun:recover-stuck-draft-runs')->assertSuccessful();

        expect(freshRun($stuck)->status)->toBe('running');

        $recent = proposedRun();
        inWorkspace($this->workspace, fn () => $recent->markRunning());

        $this->travel(1)->minutes();
        $this->artisan('noongun:recover-stuck-draft-runs')->expectsOutputToContain('Closed 1 stuck draft run(s).')->assertSuccessful();

        expect(freshRun($stuck))->toMatchObject(['status' => 'failed', 'failure_reason' => GenerateDraftJob::INTERRUPTED])
            ->and(freshRun($recent)->status)->toBe('running');
    });

    it('marks a run failed that no worker ever picked up, after the longest wait a retry can have', function () {
        $lost = proposedRun();

        $this->travel(16)->minutes();
        $this->artisan('noongun:recover-stuck-draft-runs')->assertSuccessful();

        expect(freshRun($lost)->status)->toBe('pending');

        $this->travel(1)->minutes();
        $this->artisan('noongun:recover-stuck-draft-runs')->assertSuccessful();

        expect(freshRun($lost))->toMatchObject(['status' => 'failed', 'failure_reason' => RecoverStuckDraftRuns::NEVER_STARTED]);
    });

    it('never asks a provider again, queues nothing, and a job that arrives late does nothing', function () {
        $stuck = proposedRun();
        inWorkspace($this->workspace, fn () => $stuck->markRunning());
        $lost = proposedRun();

        $this->travel(1)->hours();
        $this->artisan('noongun:recover-stuck-draft-runs')->assertSuccessful();

        runDraftJob(new GenerateDraftJob($this->workspace->id, $stuck->id));
        runDraftJob(new GenerateDraftJob($this->workspace->id, $lost->id));

        $this->generator->assertNothingGenerated();
        Queue::assertNothingPushed();

        expect(DB::table('draft_runs')->pluck('status')->unique()->all())->toBe(['failed'])
            ->and(DB::table('draft_runs')->count())->toBe(2)
            ->and(DB::table('posts')->count())->toBe(0);
    });

    it('never waits less than the time limit of a job, whatever is configured', function () {
        config(['noongun.drafting.timeout' => 600, 'noongun.drafting.limits.stuck_after_minutes' => 1]);

        $run = proposedRun();
        inWorkspace($this->workspace, fn () => $run->markRunning());

        $this->travel(20)->minutes();
        $this->artisan('noongun:recover-stuck-draft-runs')->assertSuccessful();

        expect(freshRun($run)->status)->toBe('running');
    });

    it('is scheduled', function () {
        $this->artisan('schedule:list')->expectsOutputToContain('noongun:recover-stuck-draft-runs');
    });
});
