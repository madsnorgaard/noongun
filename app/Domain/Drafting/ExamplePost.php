<?php

namespace App\Domain\Drafting;

/**
 * A text the workspace approved earlier, shown to the model as an example
 * of the voice and of what has already been said. It is the text as it was
 * approved, for one channel.
 *
 * It carries the platform, the text, the pillar name when the post had
 * one, and the id of the channel only when that channel is a target of the
 * same request. No post id, no author, no link, no metrics.
 * Actions\SelectExamplePosts chooses them. From M6 they are ranked by how
 * they did.
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
    ) {
        $pillar = $pillar !== null ? mb_substr(trim($pillar), 0, self::PILLAR_LENGTH) : null;

        $this->pillar = $pillar === '' ? null : $pillar;
    }

    /**
     * @return array{platform: string, body: string, channel_id: int|null, pillar: string|null}
     */
    public function toArray(): array
    {
        return [
            'platform' => $this->platform,
            'body' => $this->body,
            'channel_id' => $this->channelId,
            'pillar' => $this->pillar,
        ];
    }
}
