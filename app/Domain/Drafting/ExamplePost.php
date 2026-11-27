<?php

namespace App\Domain\Drafting;

/**
 * A text the workspace approved earlier, shown to the model as an example
 * of the voice and of what has already been said. It is the text as it was
 * approved, for one channel.
 *
 * It carries the platform, the text, the pillar name when the post had
 * one, the id of the channel only when that channel is a target of the
 * same request, and whether it was chosen because it did well with
 * readers. No post id, no author, no link and no numbers: of how a post
 * did, a provider learns that one yes or no and nothing else.
 * Actions\SelectExamplePosts chooses them.
 */
final readonly class ExamplePost
{
    /** The most characters of a pillar name an example carries. */
    public const PILLAR_LENGTH = 60;

    public ?string $pillar;

    public function __construct(
        public string $platform,
        public string $body,
        public ?int $channelId = null,
        ?string $pillar = null,
        public bool $performedWell = false,
    ) {
        $pillar = $pillar !== null ? mb_substr(trim($pillar), 0, self::PILLAR_LENGTH) : null;

        $this->pillar = $pillar === '' ? null : $pillar;
    }

    /**
     * @return array{platform: string, body: string, channel_id: int|null, pillar: string|null, performed_well: bool}
     */
    public function toArray(): array
    {
        return [
            'platform' => $this->platform,
            'body' => $this->body,
            'channel_id' => $this->channelId,
            'pillar' => $this->pillar,
            'performed_well' => $this->performedWell,
        ];
    }
}
