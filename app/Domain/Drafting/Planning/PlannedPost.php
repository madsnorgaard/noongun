<?php

namespace App\Domain\Drafting\Planning;

use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Idea;
use App\Domain\Content\Models\Pillar;
use Carbon\CarbonImmutable;

/**
 * One post a proposal would draft: a time, the channels whose slots fall on
 * that time, and what it is written about. One planned post becomes one
 * draft run and one post with a text per channel.
 */
final readonly class PlannedPost
{
    /**
     * @param  CarbonImmutable  $at  the planned time, in UTC
     * @param  non-empty-list<Channel>  $channels  in id order
     * @param  non-empty-list<int>  $slotIds
     */
    public function __construct(
        public CarbonImmutable $at,
        public array $channels,
        public array $slotIds,
        public ?Pillar $pillar = null,
        public ?Idea $idea = null,
    ) {}

    /**
     * @return non-empty-list<int>
     */
    public function channelIds(): array
    {
        return array_map(fn (Channel $channel): int => $channel->id, $this->channels);
    }

    public function about(?Pillar $pillar, ?Idea $idea): self
    {
        return new self($this->at, $this->channels, $this->slotIds, $pillar, $idea);
    }
}
