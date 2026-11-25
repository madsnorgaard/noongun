<?php

namespace App\Domain\Drafting;

use App\Domain\Channels\Contracts\ChannelCapabilities;

/**
 * One channel a draft is wanted for. The id is how the generated text finds
 * its way back to the channel. The channel's name, account and tokens are
 * deliberately not here: a provider gets the platform and its limits only.
 */
final readonly class TargetChannel
{
    public function __construct(
        public int $channelId,
        public string $platform,
        public ChannelCapabilities $capabilities,
    ) {}

    /**
     * @return array{channel_id: int, platform: string, max_body_length: int, link_previews: bool, media_types: list<string>, max_media_items: int}
     */
    public function toArray(): array
    {
        return [
            'channel_id' => $this->channelId,
            'platform' => $this->platform,
            'max_body_length' => $this->capabilities->maxBodyLength,
            'link_previews' => $this->capabilities->linkPreviews,
            'media_types' => $this->capabilities->mediaTypes,
            'max_media_items' => $this->capabilities->maxMediaItems,
        ];
    }
}
