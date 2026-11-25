<?php

namespace App\Domain\Channels\Contracts;

use InvalidArgumentException;

/**
 * What a platform accepts. Used by the editor for validation, by drafting
 * for prompt limits and by publishing for pacing.
 */
final readonly class ChannelCapabilities
{
    /**
     * @param  int  $maxBodyLength  longest body in characters
     * @param  list<string>  $mediaTypes  accepted MIME types, lower case; empty means no media
     * @param  int  $maxMediaItems  most media items on one post
     * @param  bool  $linkPreviews  whether the platform renders a preview for PostVariant::$link
     * @param  RateLimit|null  $rateLimit  publishing rate limit per channel, null when unknown
     */
    public function __construct(
        public int $maxBodyLength,
        public array $mediaTypes = [],
        public int $maxMediaItems = 0,
        public bool $linkPreviews = false,
        public ?RateLimit $rateLimit = null,
    ) {
        if ($maxBodyLength < 1 || $maxMediaItems < 0) {
            throw new InvalidArgumentException('Capabilities need a positive body length and no negative media count.');
        }
    }

    public function fits(string $body): bool
    {
        return mb_strlen($body) <= $this->maxBodyLength;
    }

    public function supportsMedia(string $mimeType): bool
    {
        return $this->maxMediaItems > 0 && in_array(strtolower($mimeType), $this->mediaTypes, true);
    }

    /**
     * @return array{max_body_length: int, media_types: list<string>, max_media_items: int, link_previews: bool, rate_limit: array{max_calls: int, per_seconds: int}|null}
     */
    public function toArray(): array
    {
        return [
            'max_body_length' => $this->maxBodyLength,
            'media_types' => $this->mediaTypes,
            'max_media_items' => $this->maxMediaItems,
            'link_previews' => $this->linkPreviews,
            'rate_limit' => $this->rateLimit?->toArray(),
        ];
    }
}
