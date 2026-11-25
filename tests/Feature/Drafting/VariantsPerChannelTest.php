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
use App\Domain\Content\Models\Post;
use App\Domain\Drafting\DraftRequest;
use App\Domain\Drafting\Exceptions\ProposalRefused;
use App\Domain\Drafting\Planning\PlannedPost;
use App\Domain\Drafting\TargetChannel;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
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
            ->and($run->failure_reason)->toContain('The text for LinkedIn is 301 characters long. The limit is 300.')
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
            'There is no text for LinkedIn.',
        ],
        'an empty text for the second channel' => [
            fn (DraftRequest $request) => [
                ['channel_id' => $request->channels[0]->channelId, 'body' => 'A text.'],
                ['channel_id' => $request->channels[1]->channelId, 'body' => '   '],
            ],
            'The text for LinkedIn is empty.',
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

describe('a link on a channel that shows it only with a title', function () {
    beforeEach(function () {
        $this->linkedin->withCapabilities(new ChannelCapabilities(
            maxBodyLength: SHORT_LIMIT,
            linkPreviews: true,
            linkTitleLength: 40,
            linkDescriptionLength: 60,
        ));

        [$this->workspace, $this->owner, $this->page, $this->profile] = workspaceWithTwoChannels('Africa/Johannesburg');

        slotIn($this->workspace, $this->page, 1, '09:00');
        slotIn($this->workspace, $this->profile, 1, '09:00');
    });

    /**
     * Approves every draft from the queue, the way "Approve N selected" does,
     * without opening one.
     */
    function approveFromQueue(object $test): TestResponse
    {
        $posts = Post::query()->withoutGlobalScopes()->orderBy('id')->get();

        return $test->actingAs($test->owner)->post(route('queue.approve'), [
            'posts' => $posts->map(fn ($post) => ['id' => $post->id, 'version' => postVersion($post)])->all(),
        ]);
    }

    it('drafts the title with the post, so the draft can be approved from the queue without opening it', function () {
        ideaIn($this->workspace, null, ['title' => 'Our new roast', 'source_url' => 'https://example.com/roast']);

        $this->generator->returnsOutput(fn (DraftRequest $request): array => [
            ...FakeDraftGenerator::outputFor($request),
            'link_title' => 'A lighter roast from Ethiopia',
            'link_description' => 'What changed, and how it tastes.',
        ]);

        proposeWeek($this->workspace, $this->owner);
        runQueuedDraftJobs();

        $texts = DB::table('post_variants')->get()->keyBy('channel_id');

        // Only the channel that shows the card holds it.
        expect($texts[$this->profile->id]->link)->toBe('https://example.com/roast')
            ->and($texts[$this->profile->id]->link_title)->toBe('A lighter roast from Ethiopia')
            ->and($texts[$this->profile->id]->link_description)->toBe('What changed, and how it tastes.')
            ->and($texts[$this->page->id]->link)->toBe('https://example.com/roast')
            ->and($texts[$this->page->id]->link_title)->toBeNull()
            ->and($texts[$this->page->id]->link_description)->toBeNull();

        // The model was told which channel needs the title and how long it may be.
        expect($this->generator->lastRequest()->toArray()['channels'][1])
            ->toMatchArray(['platform' => 'linkedin', 'link_title_length' => 40, 'link_description_length' => 60]);

        approveFromQueue($this)
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '1 post is approved.']);

        expect(DB::table('posts')->sole()->state)->toBe('scheduled');
    });

    it('uses the title of the idea when the model wrote none, as a run on the v1 prompt can', function () {
        ideaIn($this->workspace, null, ['title' => 'Our new roast', 'source_url' => 'https://example.com/roast']);

        $this->generator->returnsOutput(fn (DraftRequest $request): array => [
            ...FakeDraftGenerator::outputFor($request),
            'link_title' => null,
            'link_description' => null,
        ]);

        proposeWeek($this->workspace, $this->owner);
        runQueuedDraftJobs();

        expect(DB::table('draft_runs')->sole()->prompt_version)->toBe('v1')
            ->and(DB::table('post_variants')->where('channel_id', $this->profile->id)->sole()->link_title)->toBe('Our new roast')
            ->and(DB::table('post_variants')->where('channel_id', $this->page->id)->sole()->link_title)->toBeNull();

        approveFromQueue($this)->assertSessionHasNoErrors();

        expect(DB::table('posts')->sole()->state)->toBe('scheduled');
    });

    it('cuts a title and a description that are too long to what the channel takes, and does not fail the draft', function () {
        ideaIn($this->workspace, null, ['title' => 'Our new roast', 'source_url' => 'https://example.com/roast']);

        $this->generator->returnsOutput(fn (DraftRequest $request): array => [
            ...FakeDraftGenerator::outputFor($request),
            'link_title' => "A lighter roast\nfrom Ethiopia that we have been working on since March",
            'link_description' => str_repeat('word ', 30),
        ]);

        proposeWeek($this->workspace, $this->owner);
        runQueuedDraftJobs();

        $text = DB::table('post_variants')->where('channel_id', $this->profile->id)->sole();

        expect(DB::table('draft_runs')->sole()->status)->toBe('succeeded')
            ->and($text->link_title)->toBe('A lighter roast from Ethiopia that we')
            ->and(mb_strlen($text->link_description))->toBeLessThanOrEqual(60)
            ->and($text->link_description)->toEndWith('word');

        approveFromQueue($this)->assertSessionHasNoErrors();
    });

    it('leaves the title to the member when there is neither a title nor an idea, and the readiness check says so', function () {
        DB::table('briefs')->where('workspace_id', $this->workspace->id)->update([
            'calls_to_action' => json_encode([['text' => 'Book a tasting', 'url' => 'https://example.com/tasting']]),
        ]);

        $this->generator->returnsOutput(fn (DraftRequest $request): array => [
            ...FakeDraftGenerator::outputFor($request),
            'link' => 'https://example.com/tasting',
            'link_title' => '   ',
            'link_description' => null,
        ]);

        proposeWeek($this->workspace, $this->owner);
        runQueuedDraftJobs();

        $post = DB::table('posts')->sole();
        $text = DB::table('post_variants')->where('channel_id', $this->profile->id)->sole();

        expect(DB::table('draft_runs')->sole()->status)->toBe('succeeded')
            ->and($text->link)->toBe('https://example.com/tasting')
            ->and($text->link_title)->toBeNull();

        $this->actingAs($this->owner)->get(route('posts.show', $post->id))
            ->assertInertia(fn (Assert $page) => $page->where('post.problems', fn ($problems) => collect($problems)->has("variants.{$text->id}.link_title")));

        approveFromQueue($this);

        expect(DB::table('posts')->sole()->state)->toBe('draft');
    });

    it('stores no card when the link of the model was dropped', function () {
        ideaIn($this->workspace, null, ['title' => 'Our new roast']);

        $this->generator->returnsOutput(fn (DraftRequest $request): array => [
            ...FakeDraftGenerator::outputFor($request),
            'link' => 'https://elsewhere.example/landing',
            'link_title' => 'A title for a link nobody gave',
            'link_description' => 'And a description.',
        ]);

        proposeWeek($this->workspace, $this->owner);
        runQueuedDraftJobs();

        $text = DB::table('post_variants')->where('channel_id', $this->profile->id)->sole();

        expect($text->link)->toBeNull()
            ->and($text->link_title)->toBeNull()
            ->and($text->link_description)->toBeNull()
            ->and(DB::table('draft_runs')->sole()->link_dropped)->toBeTrue();

        approveFromQueue($this)->assertSessionHasNoErrors();
    });
});

describe('a channel that drops out while the week is drafted', function () {
    beforeEach(function () {
        [$this->workspace, $this->owner, $this->page, $this->profile] = workspaceWithTwoChannels('Europe/Copenhagen');

        $this->pageSlot = slotIn($this->workspace, $this->page, 1, '09:00');
        $this->profileSlot = slotIn($this->workspace, $this->profile, 1, '09:00');
    });

    it('keeps its slot open, so that proposing again drafts for it once it is connected', function () {
        $connected = DB::table('channels')->where('id', $this->profile->id)->value('status');

        proposeWeek($this->workspace, $this->owner);

        DB::table('channels')->where('id', $this->profile->id)->update(['status' => ChannelStatus::NeedsReconnect->value]);

        runQueuedDraftJobs();

        $request = json_decode(DB::table('draft_runs')->sole()->request, true);

        // The run answers only for what it wrote.
        expect($request['channel_ids'])->toBe([$this->page->id])
            ->and($request['slot_ids'])->toBe([$this->pageSlot->id])
            ->and($request['left_out_channel_ids'])->toBe([$this->profile->id])
            ->and(DB::table('post_variants')->pluck('channel_id')->all())->toBe([$this->page->id]);

        // While it is away its slot is not offered. Back again, it is.
        expect(planFor($this->workspace, '2026-11-30')->posts)->toBe([]);

        DB::table('channels')->where('id', $this->profile->id)->update(['status' => $connected]);

        expect(plannedChannels($this->workspace))->toBe([['2026-11-30 08:00', [$this->profile->id]]]);

        // Moving the first draft does not open the slot it was written for.
        DB::table('posts')->update(['scheduled_at' => '2026-12-01 10:00:00']);

        expect(plannedChannels($this->workspace))->toBe([['2026-11-30 08:00', [$this->profile->id]]]);

        proposeWeek($this->workspace, $this->owner);
        runQueuedDraftJobs();

        expect(DB::table('posts')->count())->toBe(2)
            ->and(DB::table('post_variants')->orderBy('id')->pluck('channel_id')->all())->toBe([$this->page->id, $this->profile->id])
            ->and(planFor($this->workspace, '2026-11-30')->posts)->toBe([]);
    });

    it('says on the draft which channel was left out', function () {
        proposeWeek($this->workspace, $this->owner);

        DB::table('channels')->where('id', $this->profile->id)->update(['status' => ChannelStatus::NeedsReconnect->value]);

        runQueuedDraftJobs();

        $this->actingAs($this->owner)->get(route('posts.show', DB::table('posts')->sole()->id))
            ->assertInertia(fn (Assert $page) => $page
                ->has('post.variants', 1)
                ->where('post.origin.left_out', ['Thandi Mokoena']));
    });

    it('names no channel as left out on a draft that was written for all of them', function () {
        proposeWeek($this->workspace, $this->owner);
        runQueuedDraftJobs();

        $request = json_decode(DB::table('draft_runs')->sole()->request, true);

        expect($request['slot_ids'])->toBe([$this->pageSlot->id, $this->profileSlot->id])
            ->and($request)->not->toHaveKey('left_out_channel_ids');

        $this->actingAs($this->owner)->get(route('posts.show', DB::table('posts')->sole()->id))
            ->assertInertia(fn (Assert $page) => $page->where('post.origin.left_out', []));
    });

    it('carries what was left out into the run of a retry', function () {
        proposeWeek($this->workspace, $this->owner);

        DB::table('channels')->where('id', $this->profile->id)->update(['status' => ChannelStatus::NeedsReconnect->value]);
        $this->generator->failRetryable();

        runQueuedDraftJobs();

        $retry = json_decode(DB::table('draft_runs')->orderByDesc('id')->first()->request, true);

        expect(DB::table('draft_runs')->count())->toBe(2)
            ->and($retry['slot_ids'])->toBe([$this->pageSlot->id])
            ->and($retry['left_out_channel_ids'])->toBe([$this->profile->id]);
    });
});

describe('a shared slot in a week the clocks change', function () {
    it('still gives both channels one post, at the right instant on each side of the change', function (string $now, string $week, string $timezone, array $expected) {
        $this->travelTo($now);

        [$workspace, , $page, $profile] = workspaceWithTwoChannels($timezone);

        foreach ([$page, $profile] as $channel) {
            // Saturday before the change, Sunday after it, at the same hour on the wall clock.
            slotIn($workspace, $channel, 6, '09:00');
            slotIn($workspace, $channel, 7, '09:00');
        }

        $plan = planFor($workspace, $week);
        $both = [$page->id, $profile->id];

        expect(plannedChannels($workspace, $week))->toBe([[$expected[0], $both], [$expected[1], $both]])
            ->and($plan->sharedPosts())->toBe(2)
            ->and($plan->postsForOneChannel())->toBe(0);
    })->with([
        'Copenhagen, clocks go forward on 28 March 2027' => ['2027-03-20 10:00:00', '2027-03-22', 'Europe/Copenhagen', ['2027-03-27 08:00', '2027-03-28 07:00']],
        'Johannesburg, the same week' => ['2027-03-20 10:00:00', '2027-03-22', 'Africa/Johannesburg', ['2027-03-27 07:00', '2027-03-28 07:00']],
        'Copenhagen, clocks go back on 25 October 2026' => ['2026-10-17 10:00:00', '2026-10-19', 'Europe/Copenhagen', ['2026-10-24 07:00', '2026-10-25 08:00']],
        'Johannesburg, the same week' => ['2026-10-17 10:00:00', '2026-10-19', 'Africa/Johannesburg', ['2026-10-24 07:00', '2026-10-25 07:00']],
    ]);

    it('gives both channels one post for a slot in the hour the clocks skip or repeat', function (string $now, string $week, string $time) {
        $this->travelTo($now);

        [$workspace, , $page, $profile] = workspaceWithTwoChannels('Europe/Copenhagen');

        slotIn($workspace, $page, 7, $time);
        slotIn($workspace, $profile, 7, $time);

        $planned = plannedChannels($workspace, $week);

        expect($planned)->toHaveCount(1)
            ->and($planned[0][1])->toBe([$page->id, $profile->id]);
    })->with([
        'the skipped hour' => ['2027-03-20 10:00:00', '2027-03-22', '02:30'],
        'the repeated hour' => ['2026-10-17 10:00:00', '2026-10-19', '02:30'],
    ]);
});
