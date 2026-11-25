<?php

namespace App\Domain\Drafting\Actions;

use App\Domain\Channels\ChannelManager;
use App\Domain\Channels\Exceptions\ChannelAdapterNotAvailable;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Idea;
use App\Domain\Content\Models\Pillar;
use App\Domain\Drafting\DraftRequest;
use App\Domain\Drafting\ExamplePost;
use App\Domain\Drafting\Models\Brief;
use App\Domain\Drafting\TargetChannel;
use InvalidArgumentException;

/**
 * Builds the request for one post from the records of the current
 * workspace. It copies out the few values a provider may see and leaves
 * everything else behind: of a channel only its id, its platform and what
 * the platform accepts.
 *
 * The source URL is the one given or else the idea's.
 */
final class BuildDraftRequest
{
    public function __construct(private readonly ChannelManager $channels) {}

    /**
     * @param  iterable<Channel>  $channels  at least one, each once
     * @param  list<ExamplePost>  $examples  empty until M6
     *
     * @throws InvalidArgumentException when no channel is given or one is given twice
     * @throws ChannelAdapterNotAvailable when a channel's platform has no adapter on this instance
     */
    public function handle(
        Brief $brief,
        iterable $channels,
        ?Pillar $pillar = null,
        ?Idea $idea = null,
        ?string $sourceUrl = null,
        array $examples = [],
    ): DraftRequest {
        $targets = [];

        foreach ($channels as $channel) {
            $targets[] = new TargetChannel(
                $channel->id,
                $channel->platform,
                $this->channels->for($channel)->capabilities(),
            );
        }

        return new DraftRequest(
            briefTitle: $brief->title,
            briefBody: $brief->body,
            voiceRules: $brief->voice_rules,
            channels: $targets,
            callsToAction: $brief->calls_to_action,
            pillarName: $pillar?->name,
            pillarDescription: $pillar?->description,
            ideaTitle: $idea?->title,
            ideaNotes: $idea?->notes,
            sourceUrl: $sourceUrl ?? $idea?->source_url,
            examples: $examples,
        );
    }
}
