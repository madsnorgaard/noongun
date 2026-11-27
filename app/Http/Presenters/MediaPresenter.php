<?php

namespace App\Http\Presenters;

use App\Domain\Content\Media\MediaConfig;
use App\Domain\Content\Models\Media;

/**
 * What the pages get of a stored image. The addresses lead to
 * MediaController, which checks the viewer on every request. There is no
 * address that reaches a file without it.
 */
final class MediaPresenter
{
    /**
     * @return array{id: int, url: string, thumb_url: string, mime: string, byte_size: int, width: int, height: int}
     */
    public static function present(Media $media): array
    {
        return [
            'id' => $media->id,
            'url' => route('media.show', $media->id, false),
            'thumb_url' => route('media.thumb', $media->id, false),
            'mime' => $media->mime,
            'byte_size' => $media->byte_size,
            'width' => $media->width,
            'height' => $media->height,
        ];
    }

    /**
     * What the editor needs to offer an upload: where to send it and what
     * this instance takes. The limits of a platform are with its channel.
     *
     * @return array{url: string, field: string, accept: list<string>, max_bytes: int, max_megapixels: float, max_side: int, max_per_variant: int, alt_text_length: int}
     */
    public static function uploads(): array
    {
        return [
            'url' => route('media.store', [], false),
            'field' => 'file',
            'accept' => MediaConfig::ACCEPTED_TYPES,
            'max_bytes' => MediaConfig::maxBytes(),
            'max_megapixels' => round(MediaConfig::maxPixels() / 1_000_000, 1),
            'max_side' => MediaConfig::maxSide(),
            'max_per_variant' => MediaConfig::maxPerVariant(),
            'alt_text_length' => MediaConfig::MAX_ALT_TEXT_LENGTH,
        ];
    }
}
