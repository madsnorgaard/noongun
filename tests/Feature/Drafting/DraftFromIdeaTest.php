<?php

/*
 * "Draft a post" on one idea (issue #20): one press, one draft run, one
 * post with a text for each chosen channel. It starts through the code a
 * proposed week starts through, so these tests hold that it is refused,
 * counted, retried and stopped the same way, and that what comes out is a
 * draft like any other.
 */

use App\Domain\Channels\ChannelStatus;
use App\Domain\Content\Models\Idea;
use App\Domain\Drafting\Actions\GenerateDraft;
use App\Domain\Drafting\Actions\ProposeWeek;
use App\Domain\Drafting\Actions\StopOpenRuns;
use App\Domain\Drafting\Console\RecoverStuckDraftRuns;
use App\Domain\Drafting\DraftBudget;
use App\Domain\Drafting\DraftingLimits;
use App\Domain\Drafting\Exceptions\DraftGeneratorNotAvailable;
use App\Domain\Drafting\Exceptions\ProposalRefused;
use App\Domain\Drafting\GenerateDraftJob;
use App\Domain\Drafting\Models\Brief;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Drafting\Planning\PlanIdeaDraft;
use App\Domain\Drafting\Planning\PlannedPost;
use App\Domain\Drafting\Planning\WeekPlan;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Http\Presenters\ProposalPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';
require_once __DIR__.'/../../Support/proposals.php';

beforeEach(function () {
    // A Wednesday. 11:00 in Copenhagen, 12:00 in Johannesburg.
    $this->travelTo('2026-11-25 10:00:00');
    Queue::fake();

    fakeChannelAdapter();
    $this->generator = fakeDraftGenerator();
    $this->workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->owner = userWithRole($this->workspace, Role::Owner);
    $this->alpha = channelIn($this->workspace, ['display_name' => 'Alpha']);
    $this->beta = channelIn($this->workspace, ['display_name' => 'Beta']);
    $this->brief = briefIn($this->workspace);
    $this->craft = pillarIn($this->workspace, ['name' => 'Craft', 'weekly_target' => 1]);
    $this->idea = ideaIn($this->workspace, $this->craft, ['title' => 'How we roast', 'notes' => 'Start with the green beans.']);
});

/**
 * What the page sends to start the draft it shows: the channels, the
 * planned time on the clock of the workspace and the fingerprint of the
 * plan for exactly that.
 *
 * @param  list<int>|null  $channelIds  null for every channel that can publish
 * @return array{channels: list<int>, scheduled_at: string|null, plan: string}
 */
function ideaDraftPayload(Workspace $workspace, Idea $idea, ?array $channelIds = null, ?string $at = null): array
{
    return inWorkspace($workspace, function () use ($workspace, $idea, $channelIds, $at): array {
        $plan = app(PlanIdeaDraft::class)->handle(
            Idea::query()->findOrFail($idea->id),
            $channelIds,
            $at !== null ? $workspace->toUtc($at) : null,
        );

        return ['channels' => $channelIds ?? $plan->channelIds(), 'scheduled_at' => $at, 'plan' => $plan->fingerprint()];
    });
}

/**
 * Presses the button as the owner, for the test's idea.
 *
 * @param  list<int>|null  $channelIds
 */
function draftFromIdea(?array $channelIds = null, ?string $at = null)
{
    $test = test();

    return $test->actingAs($test->owner)->post(
        route('ideas.draft.store', ['idea' => $test->idea->id]),
        ideaDraftPayload($test->workspace, $test->idea, $channelIds, $at),
    );
}

/**
 * Expects that nothing was opened, queued or asked of a provider.
 */
function expectNothingStarted(int $runs = 0): void
{
    expect(DB::table('draft_runs')->count())->toBe($runs);
    Queue::assertNothingPushed();
    test()->generator->assertNothingGenerated();
}

describe('the Ideas page', function () {
    it('offers "Draft a post" to owners and editors only', function (Role $role, bool $offered) {
        $this->actingAs(userWithRole($this->workspace, $role))->get(route('ideas.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.draft', $offered));
    })->with([[Role::Owner, true], [Role::Editor, true], [Role::Viewer, false]]);
});

describe('the page that shows what would be drafted', function () {
    it('shows the idea, the channels, the pillar, the model, the cost and the usage before anything starts', function () {
        $this->actingAs($this->owner)->get(route('ideas.draft.create', ['idea' => $this->idea->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ideas/Draft')
                ->where('plan.idea.id', $this->idea->id)
                ->where('plan.idea.title', 'How we roast')
                ->where('plan.idea.notes', 'Start with the green beans.')
                ->where('plan.idea.pillar', 'Craft')
                ->where('plan.idea.used_in', 0)
                ->where('plan.channels', [
                    ['id' => $this->alpha->id, 'name' => 'Alpha', 'platform_label' => 'Facebook', 'chosen' => true],
                    ['id' => $this->beta->id, 'name' => 'Beta', 'platform_label' => 'Facebook', 'chosen' => true],
                ])
                ->where('plan.scheduled_at', '')
                ->where('plan.problems', ['channels' => [], 'scheduled_at' => []])
                ->where('plan.fingerprint', ideaDraftPayload($this->workspace, $this->idea)['plan'])
                ->where('refusals', [])
                ->where('generator', ['provider' => 'Fake', 'model' => DRAFT_TEST_MODEL])
                ->where('cost.currency', 'USD')
                ->where('limits', ['runs_per_day' => 50, 'runs_used' => 0, 'max_attempts' => 3]));

        expectNothingStarted();
    });

    it('shows the cost ProposalCost gives for one post with the chosen channels', function (Closure $chosen) {
        $channels = $chosen($this);
        $ids = array_map(fn ($channel) => $channel->id, $channels);

        // One planned post of a week with the same channels, pillar and idea.
        $expected = inWorkspace($this->workspace, fn () => app(ProposalPresenter::class)->cost(
            $this->workspace,
            new WeekPlan('2026-11-30', CarbonImmutable::parse('2026-11-29 23:00:00'), CarbonImmutable::parse('2026-12-06 23:00:00'), [
                new PlannedPost(CarbonImmutable::parse('2026-11-30 08:00:00'), $channels, [1], $this->craft, Idea::query()->findOrFail($this->idea->id)),
            ]),
        ));

        expect($expected)->not->toBeNull();

        $this->actingAs($this->owner)->get(route('ideas.draft.create', ['idea' => $this->idea->id, 'channels' => $ids]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('cost', $expected)
                ->where('plan.channels.0.chosen', true)
                ->where('plan.channels.1.chosen', count($ids) === 2));

        expectNothingStarted();
    })->with([
        'one channel' => [fn ($test) => [$test->alpha]],
        'two channels' => [fn ($test) => [$test->alpha, $test->beta]],
    ]);

    it('says plainly that the idea is already used', function () {
        draftFromIdea([$this->alpha->id]);
        runQueuedDraftJobs();

        $this->actingAs($this->owner)->get(route('ideas.draft.create', ['idea' => $this->idea->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('plan.idea.used_in', 1)
                ->where('refusals', [])
                ->where('limits.runs_used', 1));
    });

    it('shows no pillar for an idea without one', function () {
        $loose = ideaIn($this->workspace, null, ['title' => 'Loose thought']);

        $this->actingAs($this->owner)->get(route('ideas.draft.create', ['idea' => $loose->id]))
            ->assertInertia(fn (Assert $page) => $page->where('plan.idea.pillar', null));
    });

    it('shows the planned time it was asked about and what is wrong with it', function () {
        $this->actingAs($this->owner)->get(route('ideas.draft.create', ['idea' => $this->idea->id, 'at' => '2026-11-25T11:30']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('plan.scheduled_at', '2026-11-25T11:30')
                ->where('plan.problems.scheduled_at.0', fn (string $problem) => str_contains($problem, 'less than 60 minutes away')));
    });

    it('asks for a channel when none is ticked', function () {
        $this->actingAs($this->owner)->get(route('ideas.draft.create', ['idea' => $this->idea->id, 'channels' => [0]]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('plan.channels.0.chosen', false)
                ->where('plan.channels.1.chosen', false)
                ->where('plan.problems.channels', ['Choose at least one channel.'])
                ->where('cost', null));
    });

    it('offers only the channels that can publish', function () {
        channelIn($this->workspace, ['display_name' => 'Gone', 'status' => ChannelStatus::NeedsReconnect]);

        $this->actingAs($this->owner)->get(route('ideas.draft.create', ['idea' => $this->idea->id]))
            ->assertInertia(fn (Assert $page) => $page->has('plan.channels', 2));
    });

    it('needs no slot', function () {
        expect(DB::table('slots')->count())->toBe(0);

        $this->actingAs($this->owner)->get(route('ideas.draft.create', ['idea' => $this->idea->id]))
            ->assertInertia(fn (Assert $page) => $page->where('refusals', []));
    });
});

describe('who may', function () {
    it('lets an editor see the page and start', function () {
        $editor = userWithRole($this->workspace, Role::Editor);

        $this->actingAs($editor)->get(route('ideas.draft.create', ['idea' => $this->idea->id]))->assertOk();

        $this->actingAs($editor)
            ->post(route('ideas.draft.store', ['idea' => $this->idea->id]), ideaDraftPayload($this->workspace, $this->idea))
            ->assertRedirect(route('queue.index'));

        expect(DB::table('draft_runs')->value('requested_by'))->toBe($editor->id);
    });

    it('refuses a viewer, on the page and on the start', function () {
        $viewer = userWithRole($this->workspace, Role::Viewer);

        $this->actingAs($viewer)->get(route('ideas.draft.create', ['idea' => $this->idea->id]))->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('ideas.draft.store', ['idea' => $this->idea->id]), ideaDraftPayload($this->workspace, $this->idea))
            ->assertForbidden();

        expectNothingStarted();
    });

    it('refuses a guest', function () {
        $this->post(route('ideas.draft.store', ['idea' => $this->idea->id]), ideaDraftPayload($this->workspace, $this->idea))
            ->assertRedirect(route('login'));

        expectNothingStarted();
    });
});

describe('starting', function () {
    it('opens one pending run for the idea and its pillar and queues one job', function () {
        draftFromIdea([$this->alpha->id])
            ->assertRedirect(route('queue.index'))
            ->assertSessionHasNoErrors();

        expect(DB::table('draft_runs')->count())->toBe(1);

        $run = DB::table('draft_runs')->first();
        $note = json_decode($run->request, true);

        expect($run)->toMatchObject([
            'workspace_id' => $this->workspace->id,
            'requested_by' => $this->owner->id,
            'status' => 'pending',
            'brief_id' => $this->brief->id,
            'pillar_id' => $this->craft->id,
            'idea_id' => $this->idea->id,
            'provider' => DRAFT_TEST_PROVIDER,
            'model' => DRAFT_TEST_MODEL,
            'credential_scope' => 'instance',
        ])
            ->and($note)->toMatchArray([
                'source' => 'idea',
                'planned_at' => null,
                'channel_ids' => [$this->alpha->id],
                'attempt' => 1,
            ])
            ->and($note['proposal'])->toBeString()
            ->and($note)->not->toHaveKey('week')
            ->and($note)->not->toHaveKey('slot_ids');

        Queue::assertPushed(GenerateDraftJob::class, 1);
        Queue::assertPushed(GenerateDraftJob::class, fn (GenerateDraftJob $job): bool => $job->runId === $run->id
            && $job->workspaceId === $this->workspace->id
            && $job->attempt === 1);

        // Nothing is asked of a provider by the request itself.
        $this->generator->assertNothingGenerated();
        expect(DB::table('posts')->count())->toBe(0);
    });

    it('gives one draft with one text for one channel, attributed to the run, the idea and the pillar', function () {
        draftFromIdea([$this->alpha->id]);
        runQueuedDraftJobs();

        $this->generator->assertGeneratedTimes(1);

        $run = DB::table('draft_runs')->first();
        $post = DB::table('posts')->first();

        expect(DB::table('draft_runs')->count())->toBe(1)
            ->and($run->status)->toBe('succeeded')
            ->and(DB::table('posts')->count())->toBe(1)
            ->and($post)->toMatchObject([
                'workspace_id' => $this->workspace->id,
                'state' => 'draft',
                'approved_at' => null,
                'approved_by' => null,
                'scheduled_at' => null,
                'draft_run_id' => $run->id,
                'idea_id' => $this->idea->id,
                'pillar_id' => $this->craft->id,
            ])
            ->and(DB::table('post_variants')->where('post_id', $post->id)->pluck('channel_id')->all())->toBe([$this->alpha->id]);

        expect($this->generator->lastRequest()->ideaTitle)->toBe('How we roast')
            ->and($this->generator->lastRequest()->pillarName)->toBe('Craft');
    });

    it('gives one post with two texts for two channels, from one run and one call', function () {
        draftFromIdea([$this->alpha->id, $this->beta->id]);
        runQueuedDraftJobs();

        $this->generator->assertGeneratedTimes(1);

        expect(DB::table('draft_runs')->count())->toBe(1)
            ->and(DB::table('posts')->count())->toBe(1)
            ->and(DB::table('posts')->value('state'))->toBe('draft')
            ->and(DB::table('post_variants')->orderBy('channel_id')->pluck('channel_id')->all())->toBe([$this->alpha->id, $this->beta->id])
            ->and(count($this->generator->lastRequest()->channels))->toBe(2);
    });

    it('drafts an idea without a pillar without a pillar', function () {
        $this->idea = ideaIn($this->workspace, null, ['title' => 'Loose thought']);

        draftFromIdea([$this->alpha->id]);
        runQueuedDraftJobs();

        expect(DB::table('posts')->first())->toMatchObject(['idea_id' => $this->idea->id, 'pillar_id' => null])
            ->and($this->generator->lastRequest()->pillarName)->toBeNull();
    });

    it('allows an idea that is already used, and counts both posts', function () {
        draftFromIdea([$this->alpha->id]);
        runQueuedDraftJobs();
        Queue::fake();

        draftFromIdea([$this->beta->id])->assertSessionHasNoErrors();
        runQueuedDraftJobs();

        expect(DB::table('posts')->where('idea_id', $this->idea->id)->count())->toBe(2);

        $this->actingAs($this->owner)->get(route('ideas.index'))
            ->assertInertia(fn (Assert $page) => $page->where('ideas.0.posts_count', 2));
    });
});

describe('the planned time', function () {
    it('is left empty when none is given, to decide after approval', function (string $timezone) {
        $this->workspace->update(['timezone' => $timezone]);

        draftFromIdea([$this->alpha->id])->assertSessionHasNoErrors();
        runQueuedDraftJobs();

        expect(DB::table('posts')->count())->toBe(1)
            ->and(DB::table('posts')->value('scheduled_at'))->toBeNull()
            ->and(DB::table('posts')->value('state'))->toBe('draft');
    })->with(['Europe/Copenhagen', 'Africa/Johannesburg']);

    it('is taken on the clock of the workspace and stored in UTC', function (string $timezone, string $utc) {
        $this->workspace->update(['timezone' => $timezone]);

        draftFromIdea([$this->alpha->id], '2026-11-30T09:00')->assertSessionHasNoErrors();

        expect(json_decode(DB::table('draft_runs')->value('request'), true)['planned_at'])->toBe(str_replace(' ', 'T', $utc).'+00:00');

        runQueuedDraftJobs();

        expect(DB::table('posts')->value('scheduled_at'))->toBe($utc)
            ->and(DB::table('posts')->value('state'))->toBe('draft');
    })->with([
        'Europe/Copenhagen' => ['Europe/Copenhagen', '2026-11-30 08:00:00'],
        'Africa/Johannesburg' => ['Africa/Johannesburg', '2026-11-30 07:00:00'],
    ]);

    it('is refused when it makes no sense', function (string $timezone, string $at, string $says) {
        $this->workspace->update(['timezone' => $timezone]);

        draftFromIdea([$this->alpha->id], $at)->assertSessionHasErrors('scheduled_at');

        expect(session('errors')->first('scheduled_at'))->toContain($says);
        expectNothingStarted();
    })->with([
        'passed, Copenhagen' => ['Europe/Copenhagen', '2026-11-25T10:30', 'has passed'],
        'passed, Johannesburg' => ['Africa/Johannesburg', '2026-11-25T11:30', 'has passed'],
        // 11:30 in Copenhagen and 12:30 in Johannesburg are half an hour away.
        'too close, Copenhagen' => ['Europe/Copenhagen', '2026-11-25T11:30', 'less than 60 minutes away'],
        'too close, Johannesburg' => ['Africa/Johannesburg', '2026-11-25T12:30', 'less than 60 minutes away'],
        'exactly the lead time away' => ['Europe/Copenhagen', '2026-11-25T12:00', 'less than 60 minutes away'],
        'more than a year ahead' => ['Europe/Copenhagen', '2027-12-01T09:00', 'more than a year ahead'],
    ]);

    it('is accepted a minute past the lead time', function () {
        draftFromIdea([$this->alpha->id], '2026-11-25T12:01')->assertSessionHasNoErrors();

        expect(DB::table('draft_runs')->count())->toBe(1);
    });

    it('is refused when the access of a chosen channel ends before it', function () {
        DB::table('channels')->where('id', $this->beta->id)->update(['token_expires_at' => '2026-12-01 00:00:00']);

        draftFromIdea([$this->alpha->id, $this->beta->id], '2026-12-02T09:00')->assertSessionHasErrors('scheduled_at');

        expect(session('errors')->first('scheduled_at'))->toContain('The access to Beta ends on 1 December 2026');
        expectNothingStarted();

        // Without that channel, or before the access ends, it starts.
        draftFromIdea([$this->alpha->id], '2026-12-02T09:00')->assertSessionHasNoErrors();

        expect(DB::table('draft_runs')->count())->toBe(1);
    });

    it('is refused when it is not a date and time', function () {
        $payload = [...ideaDraftPayload($this->workspace, $this->idea), 'scheduled_at' => 'next week'];

        $this->actingAs($this->owner)->post(route('ideas.draft.store', ['idea' => $this->idea->id]), $payload)
            ->assertSessionHasErrors('scheduled_at');

        expectNothingStarted();
    });
});

describe('what refuses a proposed week refuses here', function () {
    it('refuses with the same sentence and the same next step', function (Closure $arrange, string $reason, string $says, ?string $fix) {
        $arrange($this);

        $payload = ideaDraftPayload($this->workspace, $this->idea, [$this->alpha->id]);

        $this->actingAs($this->owner)->get(route('ideas.draft.create', ['idea' => $this->idea->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('refusals.0.reason', $reason)
                ->where('refusals.0.message', fn (string $message) => str_contains($message, $says))
                ->where('refusals.0.href', $fix !== null ? route($fix) : null));

        $before = DB::table('draft_runs')->count();

        $this->actingAs($this->owner)->post(route('ideas.draft.store', ['idea' => $this->idea->id]), $payload)
            ->assertSessionHasErrors();

        expect(implode(' ', session('errors')->all()))->toContain($says)
            ->and(DB::table('draft_runs')->count())->toBe($before)
            ->and(DB::table('posts')->count())->toBe(0);

        Queue::assertNothingPushed();
        $this->generator->assertNothingGenerated();
    })->with([
        'no brief' => [
            fn ($test) => DB::table('briefs')->delete(),
            'brief', 'There is no brief yet', 'brief.edit',
        ],
        'no channel that can publish' => [
            fn ($test) => DB::table('channels')->update(['status' => ChannelStatus::NeedsReconnect->value]),
            'channels', 'No channel is connected that can publish', 'channels.index',
        ],
        'no key' => [
            fn ($test) => config(['noongun.drafting.providers.'.DRAFT_TEST_PROVIDER.'.api_key' => null]),
            'key', 'No API key is set', 'llm-key.edit',
        ],
        'drafting not set up' => [
            fn ($test) => config(['noongun.drafting.provider' => 'nobody']),
            'provider', DraftGeneratorNotAvailable::FOR_MEMBERS, null,
        ],
        'the chosen provider is no longer offered' => [
            fn ($test) => DB::table('workspaces')->where('id', $test->workspace->id)->update(['drafting_provider' => 'withdrawn']),
            'provider_choice', DraftGeneratorNotAvailable::CHOICE_NOT_OFFERED, 'llm-key.edit',
        ],
        'the daily cap of the workspace' => [
            function ($test) {
                config(['noongun.drafting.limits.runs_per_day' => 1]);
                draftFromIdea([$test->alpha->id]);
                runQueuedDraftJobs();
                Queue::fake();
                $test->generator->calls = [];
                DB::table('posts')->delete();
            },
            'daily_limit', 'This workspace can draft 1 posts in 24 hours and has used 1', null,
        ],
        'the cap of the instance key' => [
            function ($test) {
                config(['noongun.drafting.limits.instance_runs_per_day' => 1]);
                $other = Workspace::factory()->create();
                draftRunIn($other);
            },
            'instance_limit', 'The API key of this instance is shared by all its workspaces', null,
        ],
        'a draft that is still at work' => [
            fn ($test) => draftRunIn($test->workspace, $test->owner),
            'in_progress', 'A post is still being drafted', 'queue.index',
        ],
    ]);

    it('refuses a channel that can no longer publish, with the reason of that channel', function () {
        $payload = ideaDraftPayload($this->workspace, $this->idea, [$this->alpha->id, $this->beta->id]);
        DB::table('channels')->where('id', $this->beta->id)->update(['status' => ChannelStatus::NeedsReconnect->value]);

        $this->actingAs($this->owner)->post(route('ideas.draft.store', ['idea' => $this->idea->id]), $payload)
            ->assertSessionHasErrors('channels');

        expect(session('errors')->first('channels'))->toBe('Beta must be connected again before it can publish.');
        expectNothingStarted();
    });

    it('refuses when no channel is sent', function () {
        $this->actingAs($this->owner)->post(route('ideas.draft.store', ['idea' => $this->idea->id]), [
            ...ideaDraftPayload($this->workspace, $this->idea),
            'channels' => [],
        ])->assertSessionHasErrors('channels');

        expectNothingStarted();
    });

    it('refuses a choice that is not the one the page showed', function () {
        $payload = ideaDraftPayload($this->workspace, $this->idea, [$this->alpha->id]);

        $this->actingAs($this->owner)->post(route('ideas.draft.store', ['idea' => $this->idea->id]), [
            ...$payload,
            'channels' => [$this->alpha->id, $this->beta->id],
        ])->assertSessionHasErrors('draft');

        $this->actingAs($this->owner)->post(route('ideas.draft.store', ['idea' => $this->idea->id]), [
            ...$payload,
            'plan' => 'made-up',
        ])->assertSessionHasErrors('draft');

        expectNothingStarted();
    });
});

describe('one press is one run', function () {
    it('refuses a second press while the first is waiting', function () {
        // Two clicks, or two tabs: both carry the same fingerprint.
        $payload = ideaDraftPayload($this->workspace, $this->idea, [$this->alpha->id]);
        $url = route('ideas.draft.store', ['idea' => $this->idea->id]);

        $this->actingAs($this->owner)->post($url, $payload)->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post($url, $payload)->assertSessionHasErrors('draft');

        expect(session('errors')->first('draft'))->toContain('still being drafted')
            ->and(DB::table('draft_runs')->count())->toBe(1);

        Queue::assertPushed(GenerateDraftJob::class, 1);
    });

    it('refuses a second press from a page that was opened before the first draft arrived', function () {
        $payload = ideaDraftPayload($this->workspace, $this->idea, [$this->alpha->id]);
        $url = route('ideas.draft.store', ['idea' => $this->idea->id]);

        $this->actingAs($this->owner)->post($url, $payload)->assertSessionHasNoErrors();
        runQueuedDraftJobs();

        $this->actingAs($this->owner)->post($url, $payload)->assertSessionHasErrors('draft');

        expect(session('errors')->first('draft'))->toContain('changed after you opened it')
            ->and(DB::table('draft_runs')->count())->toBe(1)
            ->and(DB::table('posts')->count())->toBe(1);

        $this->generator->assertGeneratedTimes(1);
    });

    it('refuses a second press from an old page when the first run failed', function () {
        $payload = ideaDraftPayload($this->workspace, $this->idea, [$this->alpha->id]);
        $url = route('ideas.draft.store', ['idea' => $this->idea->id]);
        $this->generator->failPermanently();

        $this->actingAs($this->owner)->post($url, $payload);
        runQueuedDraftJobs();

        $this->actingAs($this->owner)->post($url, $payload)->assertSessionHasErrors('draft');

        expect(DB::table('draft_runs')->count())->toBe(1);

        // From the page as it is now, the post can be asked for again.
        $this->actingAs($this->owner)->post($url, ideaDraftPayload($this->workspace, $this->idea, [$this->alpha->id]))
            ->assertSessionHasNoErrors();

        expect(DB::table('draft_runs')->count())->toBe(2);
    });
});

describe('the idea counts as used', function () {
    it('is not taken by the next proposed week', function () {
        slotIn($this->workspace, $this->alpha, 1, '09:00');

        expect(planFor($this->workspace, '2026-11-30')->posts[0]->idea?->id)->toBe($this->idea->id);

        draftFromIdea([$this->beta->id]);
        runQueuedDraftJobs();

        $plan = planFor($this->workspace, '2026-11-30');

        expect($plan->posts)->toHaveCount(1)
            ->and($plan->posts[0]->idea)->toBeNull();

        $this->actingAs($this->owner)->get(route('ideas.index'))
            ->assertInertia(fn (Assert $page) => $page->where('ideas.0.posts_count', 1));
    });

    it('is free again when the draft is rejected, as for a proposed post', function () {
        slotIn($this->workspace, $this->alpha, 1, '09:00');
        draftFromIdea([$this->beta->id]);
        runQueuedDraftJobs();

        DB::table('posts')->update(['state' => 'rejected']);

        expect(planFor($this->workspace, '2026-11-30')->posts[0]->idea?->id)->toBe($this->idea->id);
    });
});

describe('slots and targets of the week', function () {
    it('fills the slot of a channel when its planned time is that slot time', function () {
        slotIn($this->workspace, $this->alpha, 1, '09:00');
        slotIn($this->workspace, $this->beta, 1, '09:00');

        draftFromIdea([$this->alpha->id], '2026-11-30T09:00');
        runQueuedDraftJobs();

        $plan = planFor($this->workspace, '2026-11-30');

        // Alpha has its post for Monday at nine. Beta does not.
        expect($plan->taken)->toBe(1)
            ->and($plan->posts)->toHaveCount(1)
            ->and($plan->posts[0]->channelIds())->toBe([$this->beta->id])
            // The pillar of the idea has its one post of the week.
            ->and($plan->posts[0]->pillar)->toBeNull();
    });

    it('opens the slot again when the post is moved, as for a post written by hand', function () {
        slotIn($this->workspace, $this->alpha, 1, '09:00');
        draftFromIdea([$this->alpha->id], '2026-11-30T09:00');
        runQueuedDraftJobs();

        DB::table('posts')->update(['scheduled_at' => '2026-11-30 15:00:00']);

        $plan = planFor($this->workspace, '2026-11-30');

        expect($plan->taken)->toBe(0)->and($plan->posts)->toHaveCount(1);
    });
});

describe('the limits count the run', function () {
    it('counts towards the day of the workspace and of the instance key', function () {
        draftFromIdea([$this->alpha->id]);

        $used = fn () => inWorkspace($this->workspace, fn () => [app(DraftBudget::class)->usedByWorkspace(), app(DraftBudget::class)->usedOnInstanceKey()]);

        expect($used())->toBe([1, 1]);

        runQueuedDraftJobs();

        expect($used())->toBe([1, 1]);
    });

    it('holds back a proposed week while it is at work', function () {
        slotIn($this->workspace, $this->alpha, 1, '09:00');
        draftFromIdea([$this->beta->id]);

        expect(fn () => proposeWeek($this->workspace, $this->owner))->toThrow(ProposalRefused::class, 'still being drafted');
        expect(DB::table('draft_runs')->count())->toBe(1);
    });

    it('is tried again like any run, and the retry is still a run for the idea', function () {
        $this->generator->failRetryable();

        draftFromIdea([$this->alpha->id], '2026-11-30T09:00');
        runQueuedDraftJobs();

        $runs = DB::table('draft_runs')->orderBy('id')->get();
        $retry = json_decode($runs[1]->request, true);

        expect($runs)->toHaveCount(2)
            ->and($runs[0]->status)->toBe('failed')
            ->and($runs[1]->status)->toBe('succeeded')
            ->and($retry)->toMatchArray(['source' => 'idea', 'attempt' => 2, 'retry_of' => $runs[0]->id])
            ->and($runs[1]->idea_id)->toBe($this->idea->id)
            ->and(DB::table('posts')->count())->toBe(1)
            ->and(DB::table('posts')->value('scheduled_at'))->toBe('2026-11-30 08:00:00');
    });

    it('is given up by the recovery like any run', function () {
        draftFromIdea([$this->alpha->id]);

        $this->travel(DraftingLimits::unclaimedAfterSeconds() + 60)->seconds();
        $this->artisan('noongun:recover-stuck-draft-runs')->assertSuccessful();

        expect(DB::table('draft_runs')->value('status'))->toBe('failed')
            ->and(DB::table('draft_runs')->value('failure_reason'))->toContain('draft the post again from the Ideas page')
            ->and(DB::table('draft_runs')->value('failure_reason'))->not->toContain('week');
    });
});

describe('a failed run says what to do next for one post, not for a week', function () {
    it('names the Ideas page in every reason this application gives', function (string $reason) {
        $run = inWorkspace($this->workspace, fn () => DraftRun::start(
            $this->owner,
            draftingContext(),
            ['source' => DraftRun::SOURCE_IDEA],
            Brief::current(),
            null,
            Idea::query()->findOrFail($this->idea->id),
        ));

        expect($reason)->toContain('ropose the week again');

        inWorkspace($this->workspace, fn () => $run->markFailed($reason));

        expect(freshRun($run)->failure_reason)
            ->not->toContain('week')
            ->toContain('raft the post again from the Ideas page.')
            ->toEndWith('.');
    })->with([
        'not queued' => [ProposeWeek::NOT_QUEUED],
        'stopped' => [StopOpenRuns::STOPPED],
        'interrupted' => [GenerateDraftJob::INTERRUPTED],
        'not saved' => [GenerateDraft::NOT_SAVED],
        'never started' => [RecoverStuckDraftRuns::NEVER_STARTED],
        'settings changed' => ['The drafting settings changed after this draft was asked for (model). It was not drafted and nothing was spent. Propose the week again to draft it with the settings as they are now.'],
    ]);

    it('leaves the reason of a run of a proposed week as it was', function () {
        $run = draftRunIn($this->workspace, $this->owner, request: ['week' => '2026-11-30']);

        inWorkspace($this->workspace, fn () => $run->markFailed(StopOpenRuns::STOPPED));

        expect(freshRun($run)->failure_reason)->toBe(StopOpenRuns::STOPPED);
    });
});

describe('progress in the queue', function () {
    it('lands on the queue, which shows the one run as a post from an idea', function () {
        draftFromIdea([$this->alpha->id, $this->beta->id], '2026-11-30T09:00')->assertRedirect(route('queue.index'));

        $run = DB::table('draft_runs')->value('id');

        $this->actingAs($this->owner)->get(route('queue.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('drafting.proposal.kind', 'idea')
                ->where('drafting.proposal.week', '')
                ->where('drafting.proposal.idea', ['id' => $this->idea->id, 'title' => 'How we roast'])
                ->where('drafting.proposal.active', true)
                ->where('drafting.proposal.counts', ['pending' => 1, 'running' => 0, 'succeeded' => 0, 'failed' => 0])
                ->has('drafting.proposal.runs', 1)
                ->where('drafting.proposal.runs.0.id', $run)
                ->where('drafting.proposal.runs.0.planned_at', '2026-11-30T08:00:00+00:00')
                ->where('drafting.proposal.runs.0.channels', ['Alpha', 'Beta'])
                ->where('drafting.proposal.runs.0.pillar', 'Craft')
                ->where('drafting.proposal.runs.0.idea', 'How we roast'));

        runQueuedDraftJobs();

        $this->actingAs($this->owner)->get(route('queue.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('drafting.proposal.kind', 'idea')
                ->where('drafting.proposal.active', false)
                ->where('drafting.proposal.counts.succeeded', 1)
                ->where('drafting.proposal.runs.0.post_id', DB::table('posts')->value('id'))
                ->has('sections.drafts', 1));
    });

    it('shows a run without a planned time', function () {
        draftFromIdea([$this->alpha->id]);

        $this->actingAs($this->owner)->get(route('queue.index'))
            ->assertInertia(fn (Assert $page) => $page->where('drafting.proposal.runs.0.planned_at', null));
    });

    it('still calls a proposed week a week', function () {
        slotIn($this->workspace, $this->alpha, 1, '09:00');
        proposeWeek($this->workspace, $this->owner);

        $this->actingAs($this->owner)->get(route('queue.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('drafting.proposal.kind', 'week')
                ->where('drafting.proposal.idea', null)
                ->where('drafting.proposal.week', '2026-11-30'));
    });

    it('can be stopped with "Stop waiting"', function () {
        draftFromIdea([$this->alpha->id]);

        $this->travel(DraftingLimits::slowAfterSeconds() + 5)->seconds();

        $this->actingAs($this->owner)->get(route('queue.index'))
            ->assertInertia(fn (Assert $page) => $page->where('drafting.proposal.waiting_long', true));

        $this->actingAs($this->owner)->post(route('proposals.stop'))->assertRedirect(route('queue.index'));

        $run = DB::table('draft_runs')->first();

        expect($run->status)->toBe('failed')
            ->and($run->failure_reason)->toContain('someone chose to stop waiting')
            ->and($run->failure_reason)->toContain('Draft the post again from the Ideas page.')
            ->and($run->failure_reason)->not->toContain('week');

        // The job that arrives later finds the run settled.
        runQueuedDraftJobs();

        $this->generator->assertNothingGenerated();
        expect(DB::table('posts')->count())->toBe(0);

        $this->actingAs($this->owner)->get(route('queue.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('drafting.proposal.active', false)
                ->where('drafting.proposal.counts.failed', 1));
    });
});

describe('records of another workspace', function () {
    beforeEach(function () {
        $this->other = Workspace::factory()->create();
        $this->theirChannel = channelIn($this->other, ['display_name' => 'Theirs']);
        $this->theirPillar = pillarIn($this->other, ['name' => 'Their pillar']);
        $this->theirIdea = ideaIn($this->other, $this->theirPillar, ['title' => 'Their idea']);
        briefIn($this->other);
    });

    it('never shows or drafts an idea of another workspace', function () {
        $this->actingAs($this->owner)->get(route('ideas.draft.create', ['idea' => $this->theirIdea->id]))->assertNotFound();

        $this->actingAs($this->owner)->post(route('ideas.draft.store', ['idea' => $this->theirIdea->id]), [
            'channels' => [$this->alpha->id],
            'scheduled_at' => null,
            'plan' => ideaDraftPayload($this->other, $this->theirIdea)['plan'],
        ])->assertNotFound();

        expectNothingStarted();
    });

    it('never drafts for a channel of another workspace', function () {
        $this->actingAs($this->owner)->get(route('ideas.draft.create', ['idea' => $this->idea->id, 'channels' => [$this->theirChannel->id]]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('plan.channels', 2)
                ->where('plan.problems.channels', ['This channel does not exist in this workspace.']));

        foreach ([[$this->theirChannel->id], [$this->alpha->id, $this->theirChannel->id]] as $channels) {
            $this->actingAs($this->owner)->post(route('ideas.draft.store', ['idea' => $this->idea->id]), [
                'channels' => $channels,
                'scheduled_at' => null,
                'plan' => ideaDraftPayload($this->workspace, $this->idea, $channels)['plan'],
            ])->assertSessionHasErrors('channels');

            expect(session('errors')->first('channels'))->toBe('This channel does not exist in this workspace.');
        }

        expectNothingStarted();
    });

    it('takes the pillar from the idea and never from the request', function () {
        $this->actingAs($this->owner)->post(route('ideas.draft.store', ['idea' => $this->idea->id]), [
            ...ideaDraftPayload($this->workspace, $this->idea, [$this->alpha->id]),
            'pillar_id' => $this->theirPillar->id,
            'pillar' => $this->theirPillar->id,
            'idea_id' => $this->theirIdea->id,
            'workspace_id' => $this->other->id,
        ])->assertSessionHasNoErrors();

        runQueuedDraftJobs();

        expect(DB::table('draft_runs')->first())->toMatchObject([
            'workspace_id' => $this->workspace->id,
            'pillar_id' => $this->craft->id,
            'idea_id' => $this->idea->id,
        ])
            ->and(DB::table('posts')->first())->toMatchObject([
                'workspace_id' => $this->workspace->id,
                'pillar_id' => $this->craft->id,
                'idea_id' => $this->idea->id,
            ])
            ->and(DB::table('posts')->where('workspace_id', $this->other->id)->count())->toBe(0)
            ->and(DB::table('draft_runs')->where('workspace_id', $this->other->id)->count())->toBe(0);
    });
});
