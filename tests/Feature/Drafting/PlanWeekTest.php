<?php

use App\Domain\Channels\ChannelStatus;
use App\Domain\Content\PostState;
use App\Domain\Drafting\Planning\PlannedPost;
use App\Domain\Drafting\Planning\PlanWeek;
use App\Domain\Drafting\Planning\WeekPlan;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';
require_once __DIR__.'/../../Support/proposals.php';

beforeEach(function () {
    // A Wednesday. 11:00 in Copenhagen, 12:00 in Johannesburg.
    $this->travelTo('2026-11-25 10:00:00');

    fakeChannelAdapter();
    $this->copenhagen = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->johannesburg = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
    $this->channel = channelIn($this->copenhagen, ['display_name' => 'Alpha']);
});

/**
 * @return list<string>
 */
function plannedTimes(WeekPlan $plan): array
{
    return array_map(fn (PlannedPost $post): string => $post->at->format('Y-m-d H:i e'), $plan->posts);
}

/**
 * @return list<string|null>
 */
function plannedPillars(WeekPlan $plan): array
{
    return array_map(fn (PlannedPost $post): ?string => $post->pillar?->name, $plan->posts);
}

describe('times', function () {
    it('turns each slot into its time in the chosen week, in UTC and in time order', function () {
        slotIn($this->copenhagen, $this->channel, 3, '12:30');
        slotIn($this->copenhagen, $this->channel, 1, '09:00');

        $theirs = channelIn($this->johannesburg);
        slotIn($this->johannesburg, $theirs, 3, '12:30');
        slotIn($this->johannesburg, $theirs, 1, '09:00');

        expect(plannedTimes(planFor($this->copenhagen, '2026-11-30')))->toBe(['2026-11-30 08:00 UTC', '2026-12-02 11:30 UTC'])
            ->and(plannedTimes(planFor($this->johannesburg, '2026-11-30')))->toBe(['2026-11-30 07:00 UTC', '2026-12-02 10:30 UTC']);
    });

    it('keeps a slot at its hour on the wall clock through the week the clocks change in Copenhagen', function () {
        $this->travelTo('2027-03-20 10:00:00');

        slotIn($this->copenhagen, $this->channel, 6, '09:00');
        slotIn($this->copenhagen, $this->channel, 7, '09:00');

        $theirs = channelIn($this->johannesburg);
        slotIn($this->johannesburg, $theirs, 6, '09:00');
        slotIn($this->johannesburg, $theirs, 7, '09:00');

        // Copenhagen goes from UTC+1 to UTC+2 on Sunday 28 March 2027.
        expect(plannedTimes(planFor($this->copenhagen, '2027-03-22')))->toBe(['2027-03-27 08:00 UTC', '2027-03-28 07:00 UTC'])
            ->and(plannedTimes(planFor($this->johannesburg, '2027-03-22')))->toBe(['2027-03-27 07:00 UTC', '2027-03-28 07:00 UTC']);
    });

    it('plans one post when two slots of a channel land on the same instant in the skipped hour', function () {
        $this->travelTo('2027-03-20 10:00:00');

        // 02:30 does not exist on 28 March 2027 in Copenhagen and is read as 03:30.
        slotIn($this->copenhagen, $this->channel, 7, '02:30');
        slotIn($this->copenhagen, $this->channel, 7, '03:30');

        expect(plannedTimes(planFor($this->copenhagen, '2027-03-22')))->toBe(['2027-03-28 01:30 UTC']);
    });

    it('covers the week when the clocks go back as well', function () {
        $this->travelTo('2026-10-20 10:00:00');

        slotIn($this->copenhagen, $this->channel, 6, '09:00');
        slotIn($this->copenhagen, $this->channel, 7, '09:00');
        slotIn($this->copenhagen, $this->channel, 7, '23:30');

        expect(plannedTimes(planFor($this->copenhagen, '2026-10-19')))
            ->toBe(['2026-10-24 07:00 UTC', '2026-10-25 08:00 UTC', '2026-10-25 22:30 UTC']);
    });

    it('reads a slot in the hour that comes twice as the second one, in standard time, and plans it once', function () {
        $this->travelTo('2026-10-20 10:00:00');

        // On 25 October 2026 the clocks in Copenhagen go from 03:00 back to
        // 02:00, so 02:30 comes twice: at 00:30 UTC and at 01:30 UTC.
        slotIn($this->copenhagen, $this->channel, 7, '02:30');
        slotIn($this->copenhagen, $this->channel, 7, '01:30');
        slotIn($this->copenhagen, $this->channel, 7, '03:30');

        $theirs = channelIn($this->johannesburg);
        slotIn($this->johannesburg, $theirs, 7, '02:30');

        expect(plannedTimes(planFor($this->copenhagen, '2026-10-19')))
            ->toBe(['2026-10-24 23:30 UTC', '2026-10-25 01:30 UTC', '2026-10-25 02:30 UTC'])
            // Johannesburg has no such hour: 02:30 is 00:30 UTC as on any day.
            ->and(plannedTimes(planFor($this->johannesburg, '2026-10-19')))->toBe(['2026-10-25 00:30 UTC']);
    });

    it('leaves out a time that is closer than the lead time, and counts it apart from the past ones', function () {
        // Now is Wednesday 11:00 in Copenhagen. The default lead time is 60 minutes.
        slotIn($this->copenhagen, $this->channel, 3, '10:30');
        slotIn($this->copenhagen, $this->channel, 3, '11:01');
        slotIn($this->copenhagen, $this->channel, 3, '12:00');
        slotIn($this->copenhagen, $this->channel, 3, '12:01');

        $plan = planFor($this->copenhagen, '2026-11-23');

        expect(plannedTimes($plan))->toBe(['2026-11-25 11:01 UTC'])
            ->and($plan->past)->toBe(1)
            ->and($plan->soon)->toBe(2);

        config(['noongun.drafting.limits.lead_minutes' => 180]);

        $plan = planFor($this->copenhagen, '2026-11-23');

        expect($plan->posts)->toBe([])->and($plan->soon)->toBe(3);
    });

    it('applies the lead time on the clock of the workspace, in Johannesburg as well', function () {
        // Now is Wednesday 12:00 in Johannesburg.
        $theirs = channelIn($this->johannesburg);
        slotIn($this->johannesburg, $theirs, 3, '13:00');
        slotIn($this->johannesburg, $theirs, 3, '13:01');

        $plan = planFor($this->johannesburg, '2026-11-23');

        expect(plannedTimes($plan))->toBe(['2026-11-25 11:01 UTC'])->and($plan->soon)->toBe(1);
    });

    it('leaves out a time that has passed, to the minute', function () {
        config(['noongun.drafting.limits.lead_minutes' => 0]);

        slotIn($this->copenhagen, $this->channel, 1, '09:00');
        slotIn($this->copenhagen, $this->channel, 3, '10:59');
        slotIn($this->copenhagen, $this->channel, 3, '11:00');
        slotIn($this->copenhagen, $this->channel, 3, '11:01');
        slotIn($this->copenhagen, $this->channel, 4, '09:00');

        $plan = planFor($this->copenhagen, '2026-11-23');

        expect(plannedTimes($plan))->toBe(['2026-11-25 10:01 UTC', '2026-11-26 08:00 UTC'])
            ->and($plan->past)->toBe(3);
    });

    it('leaves out a time at which the channel already has a post that is not rejected', function (PostState $state, bool $holds) {
        slotIn($this->copenhagen, $this->channel, 1, '09:00');
        slotIn($this->copenhagen, $this->channel, 2, '09:00');

        $post = postInState($this->copenhagen, PostState::Draft, [$this->channel]);
        DB::table('posts')->where('id', $post->id)->update([
            'state' => $state->value,
            'scheduled_at' => '2026-11-30 08:00:00',
            'approved_at' => in_array($state, [PostState::Draft, PostState::Rejected], true) ? null : now(),
        ]);

        $plan = planFor($this->copenhagen, '2026-11-30');

        expect(count($plan->posts))->toBe($holds ? 1 : 2)
            ->and($plan->taken)->toBe($holds ? 1 : 0);
    })->with([
        'draft' => [PostState::Draft, true],
        'approved' => [PostState::Approved, true],
        'scheduled' => [PostState::Scheduled, true],
        'published' => [PostState::Published, true],
        'failed' => [PostState::Failed, true],
        'rejected' => [PostState::Rejected, false],
    ]);

    it('does not treat a post of another channel or another minute as holding the time', function () {
        $other = channelIn($this->copenhagen);
        slotIn($this->copenhagen, $this->channel, 1, '09:00');

        postInState($this->copenhagen, PostState::Draft, [$other], new DateTimeImmutable('2026-11-30 08:00:00'));
        postInState($this->copenhagen, PostState::Draft, [$this->channel], new DateTimeImmutable('2026-11-30 08:01:00'));

        expect(plannedTimes(planFor($this->copenhagen, '2026-11-30')))->toBe(['2026-11-30 08:00 UTC']);
    });

    it('skips slots of a channel that cannot publish and says how many', function (array $attributes) {
        slotIn($this->copenhagen, $this->channel, 1, '09:00');
        slotIn($this->copenhagen, channelIn($this->copenhagen, $attributes), 2, '09:00');

        $plan = planFor($this->copenhagen, '2026-11-30');

        expect(plannedTimes($plan))->toBe(['2026-11-30 08:00 UTC'])
            ->and($plan->unavailable)->toBe(1);
    })->with([
        'needs reconnect' => [['status' => ChannelStatus::NeedsReconnect]],
        'disconnected' => [['status' => ChannelStatus::Disconnected]],
        'token expired' => [['token_expires_at' => '2026-11-01 00:00:00']],
        'platform without an adapter' => [['platform' => 'unknown-platform']],
    ]);

    it('keeps the earliest times when the week has more than one proposal may draft', function () {
        config(['noongun.drafting.limits.posts_per_proposal' => 2]);

        slotIn($this->copenhagen, $this->channel, 5, '09:00');
        slotIn($this->copenhagen, $this->channel, 1, '09:00');
        slotIn($this->copenhagen, $this->channel, 3, '09:00');

        $plan = planFor($this->copenhagen, '2026-11-30');

        expect(plannedTimes($plan))->toBe(['2026-11-30 08:00 UTC', '2026-12-02 08:00 UTC'])
            ->and($plan->leftOut)->toBe(1);
    });

    it('sees only the slots and posts of its own workspace', function () {
        $theirs = channelIn($this->johannesburg);
        slotIn($this->johannesburg, $theirs, 1, '09:00');
        pillarIn($this->johannesburg, ['name' => 'Theirs', 'weekly_target' => 5]);
        ideaIn($this->johannesburg);

        slotIn($this->copenhagen, $this->channel, 2, '09:00');

        $plan = planFor($this->copenhagen, '2026-11-30');

        expect(count($plan->posts))->toBe(1)
            ->and($plan->posts[0]->channelIds())->toBe([$this->channel->id])
            ->and($plan->posts[0]->pillar)->toBeNull()
            ->and($plan->posts[0]->idea)->toBeNull();
    });
});

describe('one post for channels that share a time', function () {
    it('gives channels whose slots fall on the same instant one post with all of them', function () {
        $second = channelIn($this->copenhagen, ['display_name' => 'Beta']);

        slotIn($this->copenhagen, $this->channel, 1, '09:00');
        slotIn($this->copenhagen, $second, 1, '09:00');
        slotIn($this->copenhagen, $second, 2, '09:00');

        $plan = planFor($this->copenhagen, '2026-11-30');

        expect(count($plan->posts))->toBe(2)
            ->and($plan->posts[0]->channelIds())->toBe([$this->channel->id, $second->id])
            ->and($plan->posts[1]->channelIds())->toBe([$second->id]);
    });

    it('plans the post for the channel that is still free when the other already has one', function () {
        $second = channelIn($this->copenhagen, ['display_name' => 'Beta']);

        slotIn($this->copenhagen, $this->channel, 1, '09:00');
        slotIn($this->copenhagen, $second, 1, '09:00');
        postInState($this->copenhagen, PostState::Draft, [$this->channel], new DateTimeImmutable('2026-11-30 08:00:00'));

        $plan = planFor($this->copenhagen, '2026-11-30');

        expect(count($plan->posts))->toBe(1)
            ->and($plan->posts[0]->channelIds())->toBe([$second->id])
            ->and($plan->taken)->toBe(1);
    });
});

describe('pillars', function () {
    beforeEach(function () {
        foreach ([1, 2, 3, 4] as $weekday) {
            slotIn($this->copenhagen, $this->channel, $weekday, '09:00');
        }
    });

    it('lets the pillars take turns, largest target first, when the targets fill the week', function () {
        pillarIn($this->copenhagen, ['name' => 'Behind the scenes', 'weekly_target' => 1]);
        pillarIn($this->copenhagen, ['name' => 'Craft', 'weekly_target' => 3]);

        $plan = planFor($this->copenhagen, '2026-11-30');

        expect(plannedPillars($plan))->toBe(['Craft', 'Behind the scenes', 'Craft', 'Craft'])
            ->and($plan->short)->toBe([]);
    });

    it('breaks a tie between equal targets by name', function () {
        pillarIn($this->copenhagen, ['name' => 'Offers', 'weekly_target' => 2]);
        pillarIn($this->copenhagen, ['name' => 'Craft', 'weekly_target' => 2]);

        expect(plannedPillars(planFor($this->copenhagen, '2026-11-30')))->toBe(['Craft', 'Offers', 'Craft', 'Offers']);
    });

    it('drops the end of the sequence when the targets ask for more than the week has, and says which', function () {
        pillarIn($this->copenhagen, ['name' => 'Craft', 'weekly_target' => 3]);
        pillarIn($this->copenhagen, ['name' => 'Offers', 'weekly_target' => 2]);
        pillarIn($this->copenhagen, ['name' => 'People', 'weekly_target' => 1]);

        $plan = planFor($this->copenhagen, '2026-11-30');

        expect(plannedPillars($plan))->toBe(['Craft', 'Offers', 'People', 'Craft'])
            ->and(array_map(fn (array $short): array => [$short['pillar']->name, $short['missing']], $plan->short))
            ->toBe([['Craft', 1], ['Offers', 1]]);
    });

    it('drafts the remaining posts from the brief alone when the targets ask for fewer', function () {
        pillarIn($this->copenhagen, ['name' => 'Craft', 'weekly_target' => 1]);

        expect(plannedPillars(planFor($this->copenhagen, '2026-11-30')))->toBe(['Craft', null, null, null]);
    });

    it('drafts every post from the brief alone when there is no pillar, or none with a target', function () {
        expect(plannedPillars(planFor($this->copenhagen, '2026-11-30')))->toBe([null, null, null, null]);

        pillarIn($this->copenhagen, ['name' => 'Parked', 'weekly_target' => 0]);

        expect(plannedPillars(planFor($this->copenhagen, '2026-11-30')))->toBe([null, null, null, null]);
    });

    it('counts the posts the week already holds for a pillar towards its target', function () {
        $craft = pillarIn($this->copenhagen, ['name' => 'Craft', 'weekly_target' => 2]);
        pillarIn($this->copenhagen, ['name' => 'Offers', 'weekly_target' => 1]);

        // Monday is taken by a post for Craft. A rejected one and one in another week count for nothing.
        $held = postInState($this->copenhagen, PostState::Draft, [$this->channel], new DateTimeImmutable('2026-11-30 08:00:00'));
        $rejected = postInState($this->copenhagen, PostState::Rejected, [$this->channel]);
        $otherWeek = postInState($this->copenhagen, PostState::Draft, [$this->channel], new DateTimeImmutable('2026-12-08 08:00:00'));
        DB::table('posts')->whereIn('id', [$held->id, $rejected->id, $otherWeek->id])->update(['pillar_id' => $craft->id]);
        DB::table('posts')->where('id', $rejected->id)->update(['scheduled_at' => '2026-12-01 12:00:00']);

        expect(plannedPillars(planFor($this->copenhagen, '2026-11-30')))->toBe(['Craft', 'Offers', null]);
    });

    it('gives the same plan every time for the same records', function () {
        pillarIn($this->copenhagen, ['name' => 'Craft', 'weekly_target' => 2]);
        pillarIn($this->copenhagen, ['name' => 'Offers', 'weekly_target' => 2]);

        $first = planFor($this->copenhagen, '2026-11-30');
        $second = planFor($this->copenhagen, '2026-11-30');

        expect($second->fingerprint())->toBe($first->fingerprint());

        pillarIn($this->copenhagen, ['name' => 'Announcements', 'weekly_target' => 2]);

        expect(planFor($this->copenhagen, '2026-11-30')->fingerprint())->not->toBe($first->fingerprint())
            ->and(planFor($this->copenhagen, '2026-12-07')->fingerprint())->not->toBe($first->fingerprint());
    });
});

describe('ideas', function () {
    it('gives a post the oldest unused idea of its pillar, and a post without a pillar one without a pillar', function () {
        foreach ([1, 2, 3] as $weekday) {
            slotIn($this->copenhagen, $this->channel, $weekday, '09:00');
        }

        $craft = pillarIn($this->copenhagen, ['name' => 'Craft', 'weekly_target' => 2]);
        $other = pillarIn($this->copenhagen, ['name' => 'Parked', 'weekly_target' => 0]);

        $used = ideaIn($this->copenhagen, $craft, ['title' => 'Used']);
        $rejectedOnly = ideaIn($this->copenhagen, $craft, ['title' => 'Only on a rejected post']);
        ideaIn($this->copenhagen, $other, ['title' => 'Of a pillar that is not planned']);
        $loose = ideaIn($this->copenhagen, null, ['title' => 'Loose']);

        $usedBy = postInState($this->copenhagen, PostState::Draft, [$this->channel]);
        $rejected = postInState($this->copenhagen, PostState::Rejected, [$this->channel]);
        DB::table('posts')->where('id', $usedBy->id)->update(['idea_id' => $used->id]);
        DB::table('posts')->where('id', $rejected->id)->update(['idea_id' => $rejectedOnly->id]);

        $plan = planFor($this->copenhagen, '2026-11-30');

        expect(plannedPillars($plan))->toBe(['Craft', 'Craft', null])
            ->and(array_map(fn (PlannedPost $post): ?int => $post->idea?->id, $plan->posts))
            // The second Craft post has no idea of its pillar left and takes the loose one.
            ->toBe([$rejectedOnly->id, $loose->id, null]);
    });

    it('uses the ideas without a pillar when the pillar targets fill the week', function () {
        foreach ([1, 2, 3, 4] as $weekday) {
            slotIn($this->copenhagen, $this->channel, $weekday, '09:00');
        }

        $craft = pillarIn($this->copenhagen, ['name' => 'Craft', 'weekly_target' => 2]);
        $offers = pillarIn($this->copenhagen, ['name' => 'Offers', 'weekly_target' => 2]);
        $parked = pillarIn($this->copenhagen, ['name' => 'Parked', 'weekly_target' => 0]);

        $looseOlder = ideaIn($this->copenhagen, null, ['title' => 'Loose, older']);
        $ofCraft = ideaIn($this->copenhagen, $craft, ['title' => 'Of Craft']);
        ideaIn($this->copenhagen, $parked, ['title' => 'Of a pillar that is not planned']);
        $looseNewer = ideaIn($this->copenhagen, null, ['title' => 'Loose, newer']);

        $plan = planFor($this->copenhagen, '2026-11-30');

        // Every post has a pillar. Craft uses its own idea first; the rest
        // take the loose ones, oldest first, and never one of another pillar.
        expect(plannedPillars($plan))->toBe(['Craft', 'Offers', 'Craft', 'Offers'])
            ->and(array_map(fn (PlannedPost $post): ?int => $post->idea?->id, $plan->posts))
            ->toBe([$ofCraft->id, $looseOlder->id, $looseNewer->id, null])
            ->and($offers->id)->not->toBe($craft->id);
    });
});

describe('the week', function () {
    it('reads any day as the Monday of its week on the workspace calendar', function () {
        expect(PlanWeek::weekOf($this->copenhagen, '2026-12-03'))->toBe('2026-11-30')
            ->and(PlanWeek::weekOf($this->copenhagen, '2026-12-06'))->toBe('2026-11-30')
            ->and(PlanWeek::weekOf($this->copenhagen, '2026-12-07'))->toBe('2026-12-07')
            ->and(PlanWeek::weekOf($this->copenhagen, '2026-11-25'))->toBe('2026-11-23');
    });

    it('falls back to the current week for a date that is missing, malformed, past or too far ahead', function (?string $date) {
        expect(PlanWeek::weekOf($this->copenhagen, $date))->toBe('2026-11-23');
    })->with([null, '', 'next week', '2026-13-40', '2026-11-22', '2020-01-01', '2027-06-01']);

    it('knows the current week by the clock of the workspace, not by UTC', function () {
        // Sunday night in UTC is already Monday in Copenhagen and Johannesburg.
        $this->travelTo('2026-11-29 23:30:00');

        $utc = Workspace::factory()->create(['timezone' => 'UTC']);

        expect(PlanWeek::weekOf($utc, null))->toBe('2026-11-23')
            ->and(PlanWeek::weekOf($this->copenhagen, null))->toBe('2026-11-30')
            ->and(PlanWeek::weekOf($this->johannesburg, null))->toBe('2026-11-30');
    });

    it('allows as many weeks ahead as configured', function () {
        config(['noongun.drafting.limits.weeks_ahead' => 1]);

        expect(PlanWeek::weekOf($this->copenhagen, '2026-11-30'))->toBe('2026-11-30')
            ->and(PlanWeek::weekOf($this->copenhagen, '2026-12-07'))->toBe('2026-11-23');
    });
});
