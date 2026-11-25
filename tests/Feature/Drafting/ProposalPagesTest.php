<?php

use App\Domain\Content\PostState;
use App\Domain\Drafting\Actions\StopOpenRuns;
use App\Domain\Drafting\GenerateDraftJob;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeDraftGenerator;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';
require_once __DIR__.'/../../Support/proposals.php';

beforeEach(function () {
    // A Wednesday. 11:00 in Copenhagen.
    $this->travelTo('2026-11-25 10:00:00');
    Queue::fake();

    fakeChannelAdapter();
    $this->generator = fakeDraftGenerator();
    $this->workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->owner = userWithRole($this->workspace, Role::Owner);
    $this->channel = channelIn($this->workspace, ['display_name' => 'Alpha']);
    $this->brief = briefIn($this->workspace);
    $this->craft = pillarIn($this->workspace, ['name' => 'Craft', 'weekly_target' => 1]);

    foreach ([1, 3, 5] as $weekday) {
        slotIn($this->workspace, $this->channel, $weekday, '09:00');
    }
});

/**
 * What the page sends to start the proposal it shows.
 *
 * @return array{week: string, plan: string}
 */
function proposalPayload(Workspace $workspace, string $week = '2026-11-30'): array
{
    return ['week' => $week, 'plan' => planFor($workspace, $week)->fingerprint()];
}

describe('the page that shows the plan', function () {
    it('shows the week, the posts, the model, the cost and the usage before anything starts', function () {
        $this->actingAs($this->owner)->get(route('proposals.create', ['week' => '2026-12-02']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('proposals/Create')
                ->where('week.start', '2026-11-30')
                ->where('week.end', '2026-12-06')
                ->where('week.previous', '2026-11-23')
                ->where('week.next', '2026-12-07')
                ->where('plan.fingerprint', planFor($this->workspace, '2026-11-30')->fingerprint())
                ->has('plan.posts', 3)
                ->where('plan.posts.0.at', '2026-11-30T08:00:00+00:00')
                ->where('plan.posts.0.channels', [['name' => 'Alpha', 'platform_label' => 'Facebook']])
                ->where('plan.posts.0.pillar', 'Craft')
                ->where('plan.posts.1.pillar', null)
                ->where('plan.past', 0)
                ->where('refusals', [])
                ->where('generator', ['provider' => 'Fake', 'model' => DRAFT_TEST_MODEL])
                ->where('cost.currency', 'USD')
                // 3 posts x 1500 tokens out at 10.00 per million is 0.045; the prompts add a little.
                ->where('cost.expected', fn (string $amount) => (float) $amount >= 0.05 && (float) $amount < 0.15)
                // 3 posts x 3 attempts x 2 answers x 2000 tokens out at 10.00 per million is 0.36; the prompts add to it.
                ->where('cost.worst', fn (string $amount) => (float) $amount > 0.36 && (float) $amount < 5)
                ->where('limits.runs_used', 0)
                ->where('limits.runs_per_day', 50)
                ->where('limits.posts_per_proposal', 14)
                ->where('limits.max_attempts', 3)
                ->has('links', 4));

        expect(DB::table('draft_runs')->count())->toBe(0);
        Queue::assertNothingPushed();
        $this->generator->assertNothingGenerated();
    });

    it('opens on the current week and offers no earlier one', function (?string $week) {
        $this->actingAs($this->owner)->get(route('proposals.create', array_filter(['week' => $week])))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('week.start', '2026-11-23')
                ->where('week.previous', null)
                ->where('week.next', '2026-11-30')
                // Monday and Wednesday 09:00 have passed.
                ->has('plan.posts', 1)
                ->where('plan.past', 2));
    })->with([null, 'garbage', '2026-11-01']);

    it('gives the times of a workspace in Johannesburg on its own clock', function () {
        $workspace = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
        $owner = userWithRole($workspace, Role::Owner);
        briefIn($workspace);
        slotIn($workspace, channelIn($workspace), 1, '09:00');

        $this->actingAs($owner)->get(route('proposals.create', ['week' => '2026-11-30']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('plan.posts', 1)
                ->where('plan.posts.0.at', '2026-11-30T07:00:00+00:00'));
    });

    it('gives no cost when the model has no price', function () {
        config(['noongun.drafting.prices' => []]);

        $this->actingAs($this->owner)->get(route('proposals.create', ['week' => '2026-11-30']))
            ->assertInertia(fn (Assert $page) => $page->where('cost', null)->where('generator.model', DRAFT_TEST_MODEL));
    });

    it('names what is missing, each with where to fix it when that page exists', function () {
        DB::table('briefs')->delete();
        DB::table('channels')->update(['status' => 'needs_reconnect']);
        config(['noongun.drafting.providers.'.DRAFT_TEST_PROVIDER.'.api_key' => null]);

        $this->actingAs($this->owner)->get(route('proposals.create', ['week' => '2026-11-30']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('refusals', 3)
                ->where('refusals.0.reason', 'brief')
                ->where('refusals.0.message', fn (string $message) => str_contains($message, 'no brief'))
                ->where('refusals.1.reason', 'channels')
                ->where('refusals.1.href', route('channels.index'))
                ->where('refusals.1.label', 'Go to Channels')
                ->where('refusals.2.reason', 'key')
                ->where('refusals.2.message', fn (string $message) => str_contains($message, 'No API key is set'))
                ->where('generator', null)
                ->where('cost', null));
    });

    it('says so when the week has nothing to draft, when a proposal is at work and when the day is used up', function () {
        config(['noongun.drafting.limits.runs_per_day' => 4]);

        proposeWeek($this->workspace, $this->owner);

        $this->actingAs($this->owner)->get(route('proposals.create', ['week' => '2026-12-07']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('plan.posts', 3)
                ->where('limits.runs_used', 3)
                ->where('refusals.0.reason', 'in_progress')
                ->where('refusals.0.href', route('queue.index'))
                ->where('refusals.1.reason', 'daily_limit'));

        runQueuedDraftJobs();

        $this->get(route('proposals.create', ['week' => '2026-11-30']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('plan.posts', 0)
                ->where('plan.taken', 3)
                ->has('refusals', 1)
                ->where('refusals.0.reason', 'empty'));
    });

    it('is for owners and editors', function (Role $role, int $status) {
        $this->actingAs(userWithRole($this->workspace, $role))
            ->get(route('proposals.create'))
            ->assertStatus($status);
    })->with([[Role::Owner, 200], [Role::Editor, 200], [Role::Viewer, 403]]);

    it('asks a guest to sign in', function () {
        $this->get(route('proposals.create'))->assertRedirect(route('login'));
        $this->post(route('proposals.store'), proposalPayload($this->workspace))->assertRedirect(route('login'));

        expect(DB::table('draft_runs')->count())->toBe(0);
    });

    it('shows nothing of another workspace', function () {
        $other = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
        $stranger = userWithRole($other, Role::Owner);

        $this->actingAs($stranger)->withSession(['workspace_id' => $this->workspace->id])
            ->get(route('proposals.create', ['week' => '2026-11-30']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('plan.posts', 0)
                ->where('refusals.0.reason', 'brief'));
    });
});

describe('starting', function () {
    it('opens the runs, queues their jobs and returns to the queue', function (Role $role) {
        $this->actingAs(userWithRole($this->workspace, $role))
            ->post(route('proposals.store'), proposalPayload($this->workspace))
            ->assertRedirect(route('queue.index'))
            ->assertSessionHasNoErrors();

        expect(DB::table('draft_runs')->where('workspace_id', $this->workspace->id)->pluck('status')->all())->toBe(['pending', 'pending', 'pending'])
            ->and(DB::table('posts')->count())->toBe(0);

        Queue::assertPushed(GenerateDraftJob::class, 3);
        $this->generator->assertNothingGenerated();
    })->with([Role::Owner, Role::Editor]);

    it('refuses a viewer', function () {
        $this->actingAs(userWithRole($this->workspace, Role::Viewer))
            ->post(route('proposals.store'), proposalPayload($this->workspace))
            ->assertForbidden();

        expect(DB::table('draft_runs')->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('cannot be made to draft for another workspace', function () {
        $other = Workspace::factory()->create();
        $stranger = userWithRole($other, Role::Owner);

        $this->actingAs($stranger)->withSession(['workspace_id' => $this->workspace->id])
            ->post(route('proposals.store'), [...proposalPayload($this->workspace), 'workspace_id' => $this->workspace->id])
            ->assertSessionHasErrors('proposal');

        expect(DB::table('draft_runs')->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('explains a refusal on the page and starts nothing', function () {
        $payload = proposalPayload($this->workspace);
        slotIn($this->workspace, $this->channel, 2, '09:00');

        $this->actingAs($this->owner)->from(route('proposals.create'))
            ->post(route('proposals.store'), $payload)
            ->assertRedirect(route('proposals.create'))
            ->assertSessionHasErrors(['proposal' => 'The plan changed after you opened it. Look at it again before you start.']);

        expect(DB::table('draft_runs')->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('starts one proposal when the button is pressed twice', function () {
        $payload = proposalPayload($this->workspace);

        $this->actingAs($this->owner)->post(route('proposals.store'), $payload)->assertSessionHasNoErrors();
        $this->post(route('proposals.store'), $payload)->assertSessionHasErrors('proposal');

        expect(DB::table('draft_runs')->count())->toBe(3);
        Queue::assertPushed(GenerateDraftJob::class, 3);
    });

    it('needs a week and the fingerprint of the plan', function (array $payload, string $field) {
        $this->actingAs($this->owner)->post(route('proposals.store'), $payload)->assertSessionHasErrors($field);

        expect(DB::table('draft_runs')->count())->toBe(0);
    })->with([
        [['plan' => 'x'], 'week'],
        [['week' => 'next week', 'plan' => 'x'], 'week'],
        [['week' => '2026-11-30'], 'plan'],
    ]);

    it('is throttled', function () {
        $this->actingAs($this->owner);

        foreach (range(1, 6) as $ignored) {
            $this->post(route('proposals.store'), ['week' => '2026-11-30', 'plan' => 'stale'])->assertStatus(302);
        }

        $this->post(route('proposals.store'), ['week' => '2026-11-30', 'plan' => 'stale'])->assertStatus(429);
    });
});

describe('progress in the queue', function () {
    it('offers "Propose a week" to owners and editors only', function (Role $role, bool $offered) {
        $this->actingAs(userWithRole($this->workspace, $role))->get(route('queue.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('drafting.can_propose', $offered)
                ->where('drafting.proposal', null));
    })->with([[Role::Owner, true], [Role::Editor, true], [Role::Viewer, false]]);

    it('shows each planned post as waiting, then written, with a link to its draft', function () {
        proposeWeek($this->workspace, $this->owner);

        $this->actingAs($this->owner)->get(route('queue.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('drafting.proposal.week', '2026-11-30')
                ->where('drafting.proposal.active', true)
                ->where('drafting.proposal.requested_by', $this->owner->name)
                ->where('drafting.proposal.counts', ['pending' => 3, 'running' => 0, 'succeeded' => 0, 'failed' => 0])
                ->has('drafting.proposal.runs', 3)
                ->where('drafting.proposal.runs.0.status', 'pending')
                ->where('drafting.proposal.runs.0.planned_at', '2026-11-30T08:00:00+00:00')
                ->where('drafting.proposal.runs.0.channels', ['Alpha'])
                ->where('drafting.proposal.runs.0.pillar', 'Craft')
                ->where('drafting.proposal.runs.0.post_id', null)
                ->has('sections.drafts', 0));

        runQueuedDraftJobs();

        $firstPost = DB::table('posts')->orderBy('scheduled_at')->value('id');

        $this->get(route('queue.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('drafting.proposal.active', false)
                ->where('drafting.proposal.counts', ['pending' => 0, 'running' => 0, 'succeeded' => 3, 'failed' => 0])
                ->where('drafting.proposal.runs.0.status', 'succeeded')
                ->where('drafting.proposal.runs.0.post_id', $firstPost)
                ->has('sections.drafts', 3)
                ->where('sections.drafts.0.state', 'draft')
                ->where('sections.drafts.0.scheduled_at', '2026-11-30T08:00:00+00:00')
                ->where('sections.drafts.0.origin.pillar', 'Craft')
                ->where('sections.drafts.0.origin.model', DRAFT_TEST_MODEL)
                ->where('sections.drafts.0.can.approve', true));
    });

    it('shows why a draft failed, and a retry as one line with the earlier reason', function () {
        $runs = proposeWeek($this->workspace, $this->owner);
        $this->generator->failRetryable(message: 'The provider is busy.')->failPermanently(message: 'The provider refused the request.');

        runDraftJob(new GenerateDraftJob($this->workspace->id, $runs[0]->id));
        runDraftJob(new GenerateDraftJob($this->workspace->id, $runs[1]->id));

        $retry = DB::table('draft_runs')->where('request->retry_of', $runs[0]->id)->value('id');

        $this->actingAs($this->owner)->get(route('queue.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('drafting.proposal.active', true)
                ->where('drafting.proposal.counts', ['pending' => 2, 'running' => 0, 'succeeded' => 0, 'failed' => 1])
                ->has('drafting.proposal.runs', 3)
                ->where('drafting.proposal.runs.0.id', $retry)
                ->where('drafting.proposal.runs.0.status', 'pending')
                ->where('drafting.proposal.runs.0.attempt', 2)
                ->where('drafting.proposal.runs.0.earlier_failure', 'The provider is busy.')
                ->where('drafting.proposal.runs.1.id', $runs[1]->id)
                ->where('drafting.proposal.runs.1.status', 'failed')
                ->where('drafting.proposal.runs.1.failure_reason', 'The provider refused the request.')
                ->where('drafting.proposal.max_attempts', 3));
    });

    it('gives the progress alone when the page asks for it, without loading the lists', function () {
        proposeWeek($this->workspace, $this->owner);

        $this->actingAs($this->owner)->get(route('queue.index'))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('drafting', fn (Assert $reload) => $reload
                ->where('drafting.proposal.active', true)
                ->missing('sections')
                ->missing('totals')));
    });

    it('keeps a finished proposal in view for a while, longer when a draft failed', function () {
        $runs = proposeWeek($this->workspace, $this->owner);
        runQueuedDraftJobs();

        $this->actingAs($this->owner);

        $this->travel(59)->minutes();
        $this->get(route('queue.index'))->assertInertia(fn (Assert $page) => $page->where('drafting.proposal.active', false));

        $this->travel(2)->minutes();
        $this->get(route('queue.index'))->assertInertia(fn (Assert $page) => $page->where('drafting.proposal', null));

        DB::table('draft_runs')->where('id', $runs[0]->id)->update(['status' => 'failed', 'failure_reason' => 'The provider refused the request.']);

        $this->travel(22)->hours();
        $this->get(route('queue.index'))->assertInertia(fn (Assert $page) => $page->where('drafting.proposal.counts.failed', 1));

        $this->travel(2)->hours();
        $this->get(route('queue.index'))->assertInertia(fn (Assert $page) => $page->where('drafting.proposal', null));
    });

    it('lets a viewer of the workspace watch, and shows nothing to another workspace', function () {
        proposeWeek($this->workspace, $this->owner);

        $this->actingAs(userWithRole($this->workspace, Role::Viewer))->get(route('queue.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('drafting.can_propose', false)
                ->has('drafting.proposal.runs', 3));

        $other = Workspace::factory()->create();

        $this->actingAs(userWithRole($other, Role::Owner))->withSession(['workspace_id' => $this->workspace->id])
            ->get(route('queue.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('drafting.proposal', null)
                ->has('sections.drafts', 0));
    });

    it('has no progress on the history view', function () {
        $this->actingAs($this->owner)->get(route('queue.index', ['view' => 'history']))
            ->assertInertia(fn (Assert $page) => $page->where('drafting', null));
    });
});

describe('a queue that does not run', function () {
    it('says that it is waiting longer than expected once a draft has waited two minutes', function () {
        proposeWeek($this->workspace, $this->owner);
        $this->actingAs($this->owner);

        $this->travel(119)->seconds();
        $this->get(route('queue.index'))->assertInertia(fn (Assert $page) => $page
            ->where('drafting.proposal.active', true)
            ->where('drafting.proposal.waiting_long', false));

        $this->travel(1)->seconds();
        $this->get(route('queue.index'))->assertInertia(fn (Assert $page) => $page->where('drafting.proposal.waiting_long', true));
    });

    it('gives a retry its backoff before it counts as waiting long', function () {
        config(['noongun.drafting.limits.backoff' => [300]]);

        $runs = proposeWeek($this->workspace, $this->owner);
        $this->generator->failRetryable();

        foreach ($runs as $run) {
            runDraftJob(new GenerateDraftJob($this->workspace->id, $run->id));
        }

        expect(DB::table('draft_runs')->where('request->retry_of', $runs[0]->id)->value('status'))->toBe('pending');

        $this->actingAs($this->owner);

        $this->travel(400)->seconds();
        $this->get(route('queue.index'))->assertInertia(fn (Assert $page) => $page->where('drafting.proposal.waiting_long', false));

        $this->travel(20)->seconds();
        $this->get(route('queue.index'))->assertInertia(fn (Assert $page) => $page->where('drafting.proposal.waiting_long', true));
    });

    it('says so for a draft that has been with a worker for longer than a job may take', function () {
        config(['noongun.drafting.timeout' => 20]);

        $runs = proposeWeek($this->workspace, $this->owner);
        inWorkspace($this->workspace, fn () => $runs[0]->markRunning());
        runDraftJob(new GenerateDraftJob($this->workspace->id, $runs[1]->id));
        runDraftJob(new GenerateDraftJob($this->workspace->id, $runs[2]->id));

        $this->actingAs($this->owner);

        // The job may take 2 x 20 + 15 seconds.
        $this->travel(69)->seconds();
        $this->get(route('queue.index'))->assertInertia(fn (Assert $page) => $page->where('drafting.proposal.waiting_long', false));

        $this->travel(1)->seconds();
        $this->get(route('queue.index'))->assertInertia(fn (Assert $page) => $page->where('drafting.proposal.waiting_long', true));
    });

    it('lets an owner or editor stop waiting: the open runs fail with a reason and nothing is spent', function (Role $role) {
        $runs = proposeWeek($this->workspace, $this->owner);
        runDraftJob(new GenerateDraftJob($this->workspace->id, $runs[0]->id));
        inWorkspace($this->workspace, fn () => $runs[1]->markRunning());
        $this->generator->assertGeneratedTimes(1);

        $this->actingAs(userWithRole($this->workspace, $role))
            ->post(route('proposals.stop'))
            ->assertRedirect(route('queue.index'))
            ->assertSessionHasNoErrors();

        expect(freshRun($runs[0])->status)->toBe('succeeded')
            ->and(freshRun($runs[1]))->toMatchObject(['status' => 'failed', 'failure_reason' => StopOpenRuns::STOPPED])
            ->and(freshRun($runs[2]))->toMatchObject(['status' => 'failed', 'failure_reason' => StopOpenRuns::STOPPED])
            ->and(DB::table('posts')->count())->toBe(1);

        $this->generator->assertGeneratedTimes(1);

        $this->get(route('queue.index'))->assertInertia(fn (Assert $page) => $page
            ->where('drafting.proposal.active', false)
            ->where('drafting.proposal.waiting_long', false)
            ->where('drafting.proposal.runs.1.failure_reason', StopOpenRuns::STOPPED));
    })->with([Role::Owner, Role::Editor]);

    it('leaves no dead end: the week can be proposed again right after', function () {
        proposeWeek($this->workspace, $this->owner);

        $this->actingAs($this->owner)->post(route('proposals.stop'))->assertSessionHasNoErrors();
        $this->post(route('proposals.store'), proposalPayload($this->workspace))->assertSessionHasNoErrors();

        expect(DB::table('draft_runs')->where('status', 'pending')->count())->toBe(3)
            ->and(DB::table('draft_runs')->where('status', 'failed')->count())->toBe(3);
    });

    it('makes a job that arrives late for a stopped run do nothing', function () {
        $runs = proposeWeek($this->workspace, $this->owner);

        $this->actingAs($this->owner)->post(route('proposals.stop'));

        runQueuedDraftJobs();
        runDraftJob(new GenerateDraftJob($this->workspace->id, $runs[0]->id, 2));
        (new GenerateDraftJob($this->workspace->id, $runs[0]->id))->failed(new RuntimeException('Late.'));

        $this->generator->assertNothingGenerated();

        expect(DB::table('posts')->count())->toBe(0)
            ->and(DB::table('draft_runs')->count())->toBe(3)
            ->and(DB::table('draft_runs')->pluck('failure_reason')->unique()->all())->toBe([StopOpenRuns::STOPPED]);
    });

    it('discards the answer of a worker that was in the middle of a stopped run, without an error', function () {
        $runs = proposeWeek($this->workspace, $this->owner);

        // The stop arrives while the provider is answering.
        $this->generator->returnsOutput(function ($request) {
            inWorkspace($this->workspace, fn () => app(StopOpenRuns::class)->handle($this->owner));

            return FakeDraftGenerator::outputFor($request);
        });

        runDraftJob(new GenerateDraftJob($this->workspace->id, $runs[0]->id));

        expect(DB::table('posts')->count())->toBe(0)
            ->and(freshRun($runs[0]))->toMatchObject(['status' => 'failed', 'failure_reason' => StopOpenRuns::STOPPED]);
    });

    it('refuses a viewer and a guest', function () {
        proposeWeek($this->workspace, $this->owner);

        $this->post(route('proposals.stop'))->assertRedirect(route('login'));
        $this->actingAs(userWithRole($this->workspace, Role::Viewer))->post(route('proposals.stop'))->assertForbidden();

        expect(DB::table('draft_runs')->pluck('status')->unique()->all())->toBe(['pending']);
    });

    it('stops nothing in another workspace', function () {
        proposeWeek($this->workspace, $this->owner);

        $other = Workspace::factory()->create();
        $theirs = draftRunIn($other);

        $this->actingAs(userWithRole($other, Role::Owner))->withSession(['workspace_id' => $this->workspace->id])
            ->post(route('proposals.stop'), ['workspace_id' => $this->workspace->id])
            ->assertRedirect(route('queue.index'));

        expect(DB::table('draft_runs')->where('workspace_id', $this->workspace->id)->pluck('status')->unique()->all())->toBe(['pending'])
            ->and(freshRun($theirs)->status)->toBe('failed');
    });

    it('is throttled', function () {
        $this->actingAs($this->owner);

        foreach (range(1, 6) as $ignored) {
            $this->post(route('proposals.stop'))->assertRedirect(route('queue.index'));
        }

        expect($this->post(route('proposals.stop'))->isRedirect(route('queue.index')))->toBeFalse();
    });

    it('takes the lock a proposal takes before it reads a run', function () {
        proposeWeek($this->workspace, $this->owner);

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = strtolower($query->sql);
        });

        inWorkspace($this->workspace, fn () => app(StopOpenRuns::class)->handle($this->owner));

        $lock = array_key_first(array_filter($statements, fn (string $sql) => str_contains($sql, '"workspaces"') && str_contains($sql, 'for update')));
        $firstRun = array_key_first(array_filter($statements, fn (string $sql) => str_contains($sql, '"draft_runs"')));

        expect($lock)->not->toBeNull()->and($firstRun)->toBeGreaterThan($lock);
    });
});

describe('the editor', function () {
    it('shows where a generated draft came from, as the text the run recorded', function () {
        $idea = ideaIn($this->workspace, $this->craft, ['title' => 'How we roast']);
        $this->generator->returnsOutput(fn ($request) => [
            'variants' => [['channel_id' => $this->channel->id, 'body' => 'Fresh from the roaster.']],
            'link' => null,
            'alt_text' => 'A roaster <b>at work</b>',
            'rationale' => 'It opens the week. <script>alert(1)</script>',
        ]);

        proposeWeek($this->workspace, $this->owner);
        runQueuedDraftJobs();

        $post = DB::table('posts')->where('idea_id', $idea->id)->sole();

        $this->actingAs($this->owner)->get(route('posts.show', $post->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('posts/Edit')
                ->where('post.state', 'draft')
                ->where('post.approval', null)
                ->where('post.scheduled_at', '2026-11-30T08:00:00+00:00')
                ->where('post.origin.pillar', 'Craft')
                ->where('post.origin.idea', 'How we roast')
                ->where('post.origin.model', DRAFT_TEST_MODEL)
                ->where('post.origin.provider', 'Fake')
                ->where('post.origin.rationale', 'It opens the week. <script>alert(1)</script>')
                ->where('post.origin.alt_text', 'A roaster <b>at work</b>')
                ->where('post.origin.generated_at', '2026-11-25T10:00:00+00:00')
                ->where('post.can.approve', true));
    });

    it('shows no origin on a post written by hand', function () {
        $post = postInState($this->workspace, PostState::Draft, [$this->channel]);

        $this->actingAs($this->owner)->get(route('posts.show', $post->id))
            ->assertInertia(fn (Assert $page) => $page->where('post.origin', null));
    });

    it('does not show a generated draft, or its run, to another workspace', function () {
        proposeWeek($this->workspace, $this->owner);
        runQueuedDraftJobs();

        $other = Workspace::factory()->create();

        $this->actingAs(userWithRole($other, Role::Owner))->withSession(['workspace_id' => $this->workspace->id])
            ->get(route('posts.show', DB::table('posts')->value('id')))
            ->assertNotFound();
    });
});
