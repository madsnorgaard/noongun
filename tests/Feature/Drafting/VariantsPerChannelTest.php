<?php

/*
 * M5 (issue #5): one idea produces one variant per channel, each within
 * that channel's capabilities (ADR 0014).
 *
 * Channels with a slot at the same instant share one post: one draft run,
 * one answer, a text for each channel. Channels with slots at different
 * times get separate posts. The second platform here is a fake registered
 * as "linkedin" with a much shorter limit than the first, so that a test
 * can tell whose limit was applied.
 */

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Contracts\ChannelCapabilities;
use App\Domain\Channels\Models\Channel;
use App\Domain\Drafting\DraftRequest;
use App\Domain\Drafting\Exceptions\ProposalRefused;
use App\Domain\Drafting\Planning\PlannedPost;
use App\Domain\Drafting\TargetChannel;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeDraftGenerator;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';
require_once __DIR__.'/../../Support/proposals.php';

const SHORT_LIMIT = 300;

beforeEach(function () {
    // A Wednesday. 11:00 in Copenhagen, 12:00 in Johannesburg.
    $this->travelTo('2026-11-25 10:00:00');
    Queue::fake();

    $this->facebook = fakeChannelAdapter();
    $this->linkedin = fakeChannelAdapter('linkedin')->withCapabilities(new ChannelCapabilities(maxBodyLength: SHORT_LIMIT));
    $this->generator = fakeDraftGenerator();
});

/**
 * A workspace with a brief, a Facebook Page and a LinkedIn profile.
 *
 * @return array{Workspace, User, Channel, Channel}
 */
function workspaceWithTwoChannels(string $timezone): array
{
    $workspace = Workspace::factory()->create(['timezone' => $timezone]);
    $owner = userWithRole($workspace, Role::Owner);
    $page = channelIn($workspace, ['display_name' => 'Table Bay Coffee']);
    $profile = channelIn($workspace, ['platform' => 'linkedin', 'display_name' => 'Thandi Mokoena']);

    briefIn($workspace);

    return [$workspace, $owner, $page, $profile];
}

/**
 * @return list<array{string, list<int>}>
 */
function plannedChannels(Workspace $workspace, string $week = '2026-11-30'): array
{
    return array_map(
        fn (PlannedPost $post): array => [$post->at->format('Y-m-d H:i'), $post->channelIds()],
        planFor($workspace, $week)->posts,
    );
}

/**
 * What a model would answer: one text per target, of the given lengths.
 *
 * @param  array<string, int>  $lengths  platform => characters
 * @return Closure(DraftRequest): array<string, mixed>
 */
function textsOfLength(array $lengths): Closure
{
    return fn (DraftRequest $request): array => [
        ...FakeDraftGenerator::outputFor($request),
        'variants' => array_map(fn (TargetChannel $channel): array => [
            'channel_id' => $channel->channelId,
            'body' => str_repeat('a', $lengths[$channel->platform]),
        ], $request->channels),
    ];
}

dataset('both timezones', [
    'Copenhagen' => ['Europe/Copenhagen', '2026-11-30 08:00', '2026-12-02 11:00'],
    'Johannesburg' => ['Africa/Johannesburg', '2026-11-30 07:00', '2026-12-02 10:00'],
]);

describe('planning a week for two channels', function () {
    it('plans one post for both channels when their slots are at the same time', function (string $timezone, string $mondayNine) {
        [$workspace, , $page, $profile] = workspaceWithTwoChannels($timezone);

        slotIn($workspace, $page, 1, '09:00');
        slotIn($workspace, $profile, 1, '09:00');

        $plan = planFor($workspace, '2026-11-30');

        expect(plannedChannels($workspace))->toBe([[$mondayNine, [$page->id, $profile->id]]])
            ->and($plan->sharedPosts())->toBe(1)
            ->and($plan->postsForOneChannel())->toBe(0);
    })->with('both timezones');

    it('plans a post of its own, on its own idea, for each channel when their slots are at different times', function (string $timezone, string $mondayNine, string $wednesdayNoon) {
        [$workspace, , $page, $profile] = workspaceWithTwoChannels($timezone);
        $first = ideaIn($workspace, null, ['title' => 'The first idea']);
        $second = ideaIn($workspace, null, ['title' => 'The second idea']);

        slotIn($workspace, $profile, 3, '12:00');
        slotIn($workspace, $page, 1, '09:00');

        $plan = planFor($workspace, '2026-11-30');

        expect(plannedChannels($workspace))->toBe([[$mondayNine, [$page->id]], [$wednesdayNoon, [$profile->id]]])
            ->and($plan->posts[0]->idea?->id)->toBe($first->id)
            ->and($plan->posts[1]->idea?->id)->toBe($second->id)
            ->and($plan->sharedPosts())->toBe(0)
            ->and($plan->postsForOneChannel())->toBe(2);
    })->with('both timezones');

    it('does not join slots that are an hour apart on the same day', function (string $timezone) {
        [$workspace, , $page, $profile] = workspaceWithTwoChannels($timezone);

        slotIn($workspace, $page, 1, '09:00');
        slotIn($workspace, $profile, 1, '10:00');

        expect(plannedChannels($workspace))->toHaveCount(2);
    })->with(['Europe/Copenhagen', 'Africa/Johannesburg']);

    it('counts the shared posts and the ones that reach one channel only', function () {
        [$workspace, , $page, $profile] = workspaceWithTwoChannels('Europe/Copenhagen');

        slotIn($workspace, $page, 1, '09:00');
        slotIn($workspace, $profile, 1, '09:00');
        slotIn($workspace, $profile, 3, '12:00');

        $plan = planFor($workspace, '2026-11-30');

        expect($plan->sharedPosts())->toBe(1)
            ->and($plan->postsForOneChannel())->toBe(1);
    });

    it('has nothing to say about sharing when the plan covers one channel', function () {
        [$workspace, , $page] = workspaceWithTwoChannels('Africa/Johannesburg');

        slotIn($workspace, $page, 1, '09:00');
        slotIn($workspace, $page, 3, '09:00');

        $plan = planFor($workspace, '2026-11-30');

        expect($plan->sharedPosts())->toBe(0)
            ->and($plan->postsForOneChannel())->toBe(0);
    });

    it('shows on the plan page, before anything is spent, which posts are shared and which are not', function () {
        [$workspace, $owner, $page, $profile] = workspaceWithTwoChannels('Europe/Copenhagen');

        slotIn($workspace, $page, 1, '09:00');
        slotIn($workspace, $profile, 1, '09:00');
        slotIn($workspace, $profile, 3, '12:00');

        $this->actingAs($owner)->get(route('proposals.create', ['week' => '2026-11-30']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('plan.posts', 2)
                ->where('plan.posts.0.channels', [
                    ['name' => 'Table Bay Coffee', 'platform_label' => 'Facebook'],
                    ['name' => 'Thandi Mokoena', 'platform_label' => 'LinkedIn'],
                ])
                ->where('plan.posts.1.channels', [['name' => 'Thandi Mokoena', 'platform_label' => 'LinkedIn']])
                ->where('plan.shared', 1)
                ->where('plan.for_one_channel', 1));

        expect(DB::table('draft_runs')->count())->toBe(0);
        $this->generator->assertNothingGenerated();
    });

    it('refuses to start when a channel joined a time after the plan was shown', function () {
        [$workspace, $owner, $page, $profile] = workspaceWithTwoChannels('Africa/Johannesburg');

        slotIn($workspace, $page, 1, '09:00');
        $seen = planFor($workspace, '2026-11-30')->fingerprint();

        slotIn($workspace, $profile, 1, '09:00');

        expect(fn () => proposeWeek($workspace, $owner, '2026-11-30', $seen))
            ->toThrow(fn (ProposalRefused $refusal) => expect($refusal->reason)->toBe(ProposalRefused::PLAN_CHANGED));

        expect(DB::table('draft_runs')->count())->toBe(0);
    });
});

describe('drafting one post for two channels', function () {
    beforeEach(function () {
        [$this->workspace, $this->owner, $this->page, $this->profile] = workspaceWithTwoChannels('Europe/Copenhagen');

        slotIn($this->workspace, $this->page, 1, '09:00');
        slotIn($this->workspace, $this->profile, 1, '09:00');
    });

    it('asks once and gets one draft with a text for each channel, at one planned time', function (string $timezone, string $mondayNine) {
        [$workspace, $owner, $page, $profile] = workspaceWithTwoChannels($timezone);
        $idea = ideaIn($workspace, null, ['title' => 'One idea for both', 'source_url' => 'https://example.com/both']);

        slotIn($workspace, $page, 1, '09:00');
        slotIn($workspace, $profile, 1, '09:00');

        $this->actingAs($owner)
            ->post(route('proposals.store'), ['week' => '2026-11-30', 'plan' => planFor($workspace, '2026-11-30')->fingerprint()])
            ->assertSessionHasNoErrors();

        runQueuedDraftJobs();

        // One run, one call, one cost.
        $this->generator->assertGeneratedTimes(1);

        $run = DB::table('draft_runs')->where('workspace_id', $workspace->id)->sole();
        $post = DB::table('posts')->where('workspace_id', $workspace->id)->sole();
        $variants = DB::table('post_variants')->where('post_id', $post->id)->orderBy('channel_id')->get();

        expect($run->status)->toBe('succeeded')
            ->and($run->idea_id)->toBe($idea->id)
            ->and($run->cost_estimate)->not->toBeNull()
            ->and($post->state)->toBe('draft')
            ->and($post->approved_at)->toBeNull()
            ->and($post->draft_run_id)->toBe($run->id)
            ->and($post->idea_id)->toBe($idea->id)
            ->and(substr($post->scheduled_at, 0, 16))->toBe($mondayNine)
            ->and($variants)->toHaveCount(2)
            ->and($variants[0]->channel_id)->toBe($page->id)
            ->and($variants[0]->body)->toBe('Generated draft 1 for facebook.')
            ->and($variants[1]->channel_id)->toBe($profile->id)
            ->and($variants[1]->body)->toBe('Generated draft 1 for linkedin.')
            ->and($variants[0]->link)->toBe('https://example.com/both')
            ->and($variants[1]->link)->toBe('https://example.com/both');

        // The request named both channels, each with its own platform and limit.
        $targets = array_map(
            fn (TargetChannel $target): array => [$target->channelId, $target->platform, $target->capabilities->maxBodyLength],
            $this->generator->lastRequest()->channels,
        );

        expect($targets)->toBe([[$page->id, 'facebook', 5000], [$profile->id, 'linkedin', SHORT_LIMIT]]);
    })->with('both timezones');

    it('holds each text to the limit of its own channel', function () {
        // 2000 characters fit Facebook and would not fit LinkedIn.
        $this->generator->returnsOutput(textsOfLength(['facebook' => 2000, 'linkedin' => SHORT_LIMIT]));

        proposeWeek($this->workspace, $this->owner);
        runQueuedDraftJobs();

        $lengths = DB::table('post_variants')->orderBy('channel_id')->pluck('body')->map(fn (string $body): int => mb_strlen($body))->all();

        expect($lengths)->toBe([2000, SHORT_LIMIT])
            ->and(DB::table('draft_runs')->sole()->status)->toBe('succeeded');
    });

    it('refuses the whole draft when the text for one channel is over that channel\'s limit', function () {
        $this->generator->returnsOutput(textsOfLength(['facebook' => 200, 'linkedin' => SHORT_LIMIT + 1]));

        proposeWeek($this->workspace, $this->owner);
        runQueuedDraftJobs();

        $run = DB::table('draft_runs')->sole();

        expect($run->status)->toBe('failed')
            ->and($run->failure_reason)->toContain('The text for Linkedin is 301 characters long. The limit is 300.')
            ->and($run->failure_reason)->not->toContain('Facebook')
            // The answer was paid for, also when it could not be used.
            ->and($run->input_tokens)->toBe(FakeDraftGenerator::INPUT_TOKENS)
            ->and(DB::table('posts')->count())->toBe(0)
            ->and(DB::table('post_variants')->count())->toBe(0);
    });

    it('refuses the whole draft when a channel is left without a text', function (Closure $variants, string $reason) {
        $this->generator->returnsOutput(fn (DraftRequest $request): array => [
            ...FakeDraftGenerator::outputFor($request),
            'variants' => $variants($request),
        ]);

        proposeWeek($this->workspace, $this->owner);
        runQueuedDraftJobs();

        $run = DB::table('draft_runs')->sole();

        expect($run->status)->toBe('failed')
            ->and($run->failure_reason)->toContain($reason)
            ->and(DB::table('posts')->count())->toBe(0);
    })->with([
        'no entry for the second channel' => [
            fn (DraftRequest $request) => [['channel_id' => $request->channels[0]->channelId, 'body' => 'Only one text.']],
            'There is no text for Linkedin.',
        ],
        'an empty text for the second channel' => [
            fn (DraftRequest $request) => [
                ['channel_id' => $request->channels[0]->channelId, 'body' => 'A text.'],
                ['channel_id' => $request->channels[1]->channelId, 'body' => '   '],
            ],
            'The text for Linkedin is empty.',
        ],
        'the first channel twice' => [
            fn (DraftRequest $request) => [
                ['channel_id' => $request->channels[0]->channelId, 'body' => 'A text.'],
                ['channel_id' => $request->channels[0]->channelId, 'body' => 'The same channel again.'],
            ],
            'There is more than one text for Facebook.',
        ],
        'a channel that was not asked for' => [
            fn (DraftRequest $request) => [
                ['channel_id' => $request->channels[0]->channelId, 'body' => 'A text.'],
                ['channel_id' => $request->channels[1]->channelId, 'body' => 'A text.'],
                ['channel_id' => 999999, 'body' => 'A text for nobody.'],
            ],
            'There is a text for a channel that was not asked for.',
        ],
    ]);

    it('drafts for the channel that is left when the other was disconnected after the plan was started', function () {
        proposeWeek($this->workspace, $this->owner);

        DB::table('channels')->where('id', $this->profile->id)->update(['status' => ChannelStatus::Disconnected->value]);

        runQueuedDraftJobs();

        expect(DB::table('post_variants')->pluck('channel_id')->all())->toBe([$this->page->id])
            ->and($this->generator->lastRequest()->channelIds())->toBe([$this->page->id]);
    });

    it('never drafts for a channel of another workspace, also when a run names it', function () {
        [$other, , $theirPage] = workspaceWithTwoChannels('Africa/Johannesburg');

        [$run] = proposeWeek($this->workspace, $this->owner);

        DB::table('draft_runs')->where('id', $run->id)->update([
            'request' => json_encode([...$run->request, 'channel_ids' => [$this->page->id, $theirPage->id]]),
        ]);

        runQueuedDraftJobs();

        expect($this->generator->lastRequest()->channelIds())->toBe([$this->page->id])
            ->and(DB::table('post_variants')->pluck('channel_id')->all())->toBe([$this->page->id])
            ->and(DB::table('posts')->where('workspace_id', $other->id)->count())->toBe(0);
    });
});
