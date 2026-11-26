<?php

namespace App\Domain\Drafting\Planning;

use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Idea;
use App\Domain\Content\Models\Pillar;
use Carbon\CarbonImmutable;

/**
 * What "Draft a post" on an idea would do, worked out without creating
 * anything: one post, with a text for each chosen channel, about the idea
 * and the pillar the idea belongs to. The rule is in PlanIdeaDraft.
 */
final readonly class IdeaDraftPlan
{
    /**
     * @param  list<Channel>  $offered  every channel a post can be drafted for, in id order
     * @param  list<Channel>  $channels  the ones of those that were chosen, in id order
     * @param  CarbonImmutable|null  $plannedAt  in UTC; null to decide after approval
     * @param  int  $usedIn  how many posts were already drafted from the idea
     * @param  int|null  $latestRunId  the newest draft run for the idea, whatever became of it
     * @param  list<string>  $channelProblems  why the chosen channels cannot be drafted for as asked
     * @param  list<string>  $timeProblems  why the planned time makes no sense
     */
    public function __construct(
        public Idea $idea,
        public ?Pillar $pillar,
        public array $offered,
        public array $channels,
        public ?CarbonImmutable $plannedAt,
        public int $usedIn,
        public ?int $latestRunId,
        public array $channelProblems = [],
        public array $timeProblems = [],
    ) {}

    /**
     * @return list<int>
     */
    public function channelIds(): array
    {
        return array_map(fn (Channel $channel): int => $channel->id, $this->channels);
    }

    /**
     * A fingerprint of what would be drafted and of what the idea has
     * been used for so far. The page sends it back with the request that
     * starts the draft. A request from a page that is out of date is
     * refused: the choice changed, or the idea got a draft run in the
     * meantime, which is what a second press in another tab finds.
     */
    public function fingerprint(): string
    {
        return substr(hash('sha256', (string) json_encode([
            'idea',
            $this->idea->id,
            $this->pillar?->id,
            $this->channelIds(),
            $this->plannedAt?->getTimestamp(),
            $this->usedIn,
            $this->latestRunId,
        ])), 0, 20);
    }
}
