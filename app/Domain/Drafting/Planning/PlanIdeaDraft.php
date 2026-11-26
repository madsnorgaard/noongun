<?php

namespace App\Domain\Drafting\Planning;

use App\Domain\Channels\ChannelManager;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Idea;
use App\Domain\Content\PostPreconditions;
use App\Domain\Drafting\DraftingLimits;
use App\Domain\Drafting\Models\DraftRun;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Works out what "Draft a post" on one idea would draft. It reads and
 * creates nothing.
 *
 * - Channels. A post can be drafted for every channel that can publish.
 *   When none is named, all of them are chosen. A channel that is named
 *   but cannot publish, or is not a channel of this workspace, is a
 *   problem and is not drafted for.
 * - Pillar. The pillar of the idea, or none. It is never chosen here.
 * - Planned time. None means the person decides after approval, as for a
 *   post written by hand. A time that is given must be further away than
 *   the lead time of drafting (noongun.drafting.limits.lead_minutes), for
 *   the reason PlanWeek leaves such a slot time out, and must pass the
 *   check a scheduled time passes (PostPreconditions::timeProblems()): not
 *   more than a year ahead, and not after the access of a chosen channel
 *   ends.
 */
final class PlanIdeaDraft
{
    public function __construct(
        private readonly ProposalChecks $checks,
        private readonly PostPreconditions $preconditions,
        private readonly ChannelManager $channels,
    ) {}

    /**
     * @param  Idea  $idea  an idea of the current workspace
     * @param  list<int>|null  $channelIds  null for every channel that can publish
     * @param  DateTimeInterface|null  $plannedAt  an instant
     */
    public function handle(Idea $idea, ?array $channelIds = null, ?DateTimeInterface $plannedAt = null, ?DateTimeInterface $now = null): IdeaDraftPlan
    {
        $now = CarbonImmutable::instance($now ?? now());
        $plannedAt = $plannedAt !== null ? CarbonImmutable::instance($plannedAt)->utc() : null;

        $offered = $this->checks->publishingChannels();
        $chosen = $channelIds === null ? $offered : $offered->whereIn('id', $channelIds)->values();
        $channelProblems = [];

        if ($channelIds !== null) {
            $unknown = array_values(array_diff($channelIds, $offered->modelKeys()));
            $known = Channel::query()->whereKey($unknown)->orderBy('id')->get()->keyBy('id');

            foreach ($unknown as $channelId) {
                $channel = $known->get($channelId);

                $channelProblems[] = match (true) {
                    $channel === null => (string) __('This channel does not exist in this workspace.'),
                    ! $this->channels->has($channel->platform) => PostPreconditions::platformUnavailable($channel),
                    default => PostPreconditions::channelProblem($channel) ?? (string) __(':channel cannot publish right now.', ['channel' => $channel->display_name]),
                };
            }
        }

        if ($chosen->isEmpty() && $channelProblems === [] && $offered->isNotEmpty()) {
            $channelProblems[] = (string) __('Choose at least one channel.');
        }

        return new IdeaDraftPlan(
            idea: $idea,
            pillar: $idea->pillar,
            offered: array_values($offered->all()),
            channels: array_values($chosen->all()),
            plannedAt: $plannedAt,
            usedIn: $idea->posts()->count(),
            latestRunId: self::latestRunFor($idea),
            channelProblems: array_values(array_unique($channelProblems)),
            timeProblems: $plannedAt !== null ? $this->timeProblems(array_values($chosen->all()), $plannedAt, $now) : [],
        );
    }

    /**
     * @param  list<Channel>  $channels
     * @return list<string>
     */
    private function timeProblems(array $channels, CarbonImmutable $at, CarbonImmutable $now): array
    {
        if ($at->lte($now)) {
            return [(string) __('The planned time has passed. Choose a time in the future, or leave it empty to decide after approval.')];
        }

        $lead = DraftingLimits::leadSeconds();

        // The rule of PlanWeek for a slot time: too close to be drafted,
        // read and approved before it passes.
        if ($at->getTimestamp() <= $now->getTimestamp() + $lead) {
            return [(string) __('The planned time is less than :minutes minutes away. A draft could not be read and approved before then. Choose a later time, or leave it empty to decide after approval.', [
                'minutes' => intdiv($lead, 60),
            ])];
        }

        return $this->preconditions->timeProblems($channels, $at);
    }

    private static function latestRunFor(Idea $idea): ?int
    {
        $latest = DraftRun::query()->where('idea_id', $idea->id)->max('id');

        return is_numeric($latest) ? (int) $latest : null;
    }
}
