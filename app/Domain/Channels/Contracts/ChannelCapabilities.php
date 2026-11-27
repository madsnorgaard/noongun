<?php

namespace App\Domain\Channels\Contracts;

use InvalidArgumentException;

/**
 * What a platform accepts. Used by the editor for validation, by drafting
 * for prompt limits and by publishing for pacing.
 */
final readonly class ChannelCapabilities
{
    /** What comes between the text and a link that is sent as its last line. */
    public const LINK_SEPARATOR = "\n\n";

    /**
     * @param  int  $maxBodyLength  longest body in characters
     * @param  list<string>  $mediaTypes  accepted MIME types, lower case; empty means no media
     * @param  int  $maxMediaItems  most media items on one post
     * @param  bool  $linkPreviews  whether the platform renders a preview for PostVariant::$link
     * @param  RateLimit|null  $rateLimit  publishing rate limit per channel, null when unknown
     * @param  int|null  $linkTitleLength  longest title of a link card the author must supply, in characters; null when the platform builds the card itself or shows none
     * @param  int|null  $linkDescriptionLength  longest description of such a card; only read when $linkTitleLength is set
     * @param  int|null  $maxMediaBytes  largest file of one media item in bytes; null when the platform names no limit
     * @param  int|null  $maxMediaPixels  most pixels (width x height) of one image; null when the platform names no limit
     * @param  int|null  $altTextLength  longest alternative text of an image in characters; null when the platform takes none
     * @param  MediaLinkRule  $mediaLinkRule  what happens to the link of a post that has images
     * @param  int|null  $mediaUploadLifetime  seconds the platform keeps an uploaded image that is on no post yet; null when it keeps it. An upload older than this is made again.
     */
    public function __construct(
        public int $maxBodyLength,
        public array $mediaTypes = [],
        public int $maxMediaItems = 0,
        public bool $linkPreviews = false,
        public ?RateLimit $rateLimit = null,
        public ?int $linkTitleLength = null,
        public ?int $linkDescriptionLength = null,
        public ?int $maxMediaBytes = null,
        public ?int $maxMediaPixels = null,
        public ?int $altTextLength = null,
        public MediaLinkRule $mediaLinkRule = MediaLinkRule::InText,
        public ?int $mediaUploadLifetime = null,
    ) {
        if ($maxBodyLength < 1 || $maxMediaItems < 0) {
            throw new InvalidArgumentException('Capabilities need a positive body length and no negative media count.');
        }

        if (($linkTitleLength !== null && $linkTitleLength < 1) || ($linkDescriptionLength !== null && $linkDescriptionLength < 1)) {
            throw new InvalidArgumentException('The lengths of a link card must be positive when they are given.');
        }

        foreach ([$maxMediaBytes, $maxMediaPixels, $altTextLength, $mediaUploadLifetime] as $limit) {
            if ($limit !== null && $limit < 1) {
                throw new InvalidArgumentException('The limits for media must be positive when they are given.');
            }
        }
    }

    public function fits(string $body): bool
    {
        return mb_strlen($body) <= $this->maxBodyLength;
    }

    /**
     * True when a link is shown as a card only from the title the author
     * gives, because the platform reads nothing from the linked page.
     */
    public function needsLinkCard(): bool
    {
        return $this->linkTitleLength !== null;
    }

    public function acceptsMedia(): bool
    {
        return $this->maxMediaItems > 0 && $this->mediaTypes !== [];
    }

    public function supportsMedia(string $mimeType): bool
    {
        return $this->maxMediaItems > 0 && in_array(strtolower($mimeType), $this->mediaTypes, true);
    }

    /**
     * True when the link of a post with this many images is sent as the
     * last line of its text, because the images take the place of the
     * preview or the card.
     */
    public function linkGoesInText(?string $link, int $images): bool
    {
        return $images > 0 && $this->mediaLinkRule === MediaLinkRule::InText && trim((string) $link) !== '';
    }

    /**
     * The text as the platform gets it: the body, and after it the link
     * when linkGoesInText(). The readiness check measures this text against
     * the limit, and an adapter sends exactly this, so that what was
     * approved is what goes out.
     *
     * $link is the link as it is sent, with its campaign parameters, and
     * $typedLink the link as the author typed it. A reader gets the link
     * once: a body that already ends with either of them is not given a
     * second one. Where it ends with the typed link, that is replaced by
     * the link that is sent, so that a click is still counted for the
     * campaign.
     */
    public function textFor(string $body, ?string $link, int $images, ?string $typedLink = null): string
    {
        $link = trim((string) $link);

        if (! $this->linkGoesInText($link, $images)) {
            return $body;
        }

        $text = rtrim($body);

        if (self::endsWithLink($text, $link)) {
            return $body;
        }

        $typed = trim((string) $typedLink);

        if ($typed !== '' && self::endsWithLink($text, $typed)) {
            return substr($text, 0, -strlen($typed)).$link;
        }

        return $text === '' ? $link : $text.self::LINK_SEPARATOR.$link;
    }

    /**
     * Whether the text ends with the link as a word of its own, and not
     * with another address that only ends the same way.
     */
    private static function endsWithLink(string $text, string $link): bool
    {
        if (! str_ends_with($text, $link)) {
            return false;
        }

        $before = substr($text, 0, -strlen($link));

        return $before === '' || preg_match('/\s$/u', $before) === 1;
    }

    /**
     * @return array{max_body_length: int, media_types: list<string>, max_media_items: int, link_previews: bool, rate_limit: array{max_calls: int, per_seconds: int}|null, link_title_length: int|null, link_description_length: int|null, max_media_bytes: int|null, max_media_pixels: int|null, alt_text_length: int|null, media_link_rule: string, media_upload_lifetime: int|null}
     */
    public function toArray(): array
    {
        return [
            'max_body_length' => $this->maxBodyLength,
            'media_types' => $this->mediaTypes,
            'max_media_items' => $this->maxMediaItems,
            'link_previews' => $this->linkPreviews,
            'rate_limit' => $this->rateLimit?->toArray(),
            'link_title_length' => $this->linkTitleLength,
            'link_description_length' => $this->linkDescriptionLength,
            'max_media_bytes' => $this->maxMediaBytes,
            'max_media_pixels' => $this->maxMediaPixels,
            'alt_text_length' => $this->altTextLength,
            'media_link_rule' => $this->mediaLinkRule->value,
            'media_upload_lifetime' => $this->mediaUploadLifetime,
        ];
    }
}
