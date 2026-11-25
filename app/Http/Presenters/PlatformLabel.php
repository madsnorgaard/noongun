<?php

namespace App\Http\Presenters;

use Illuminate\Support\Str;

/**
 * The name of a platform as a page shows it. Every page takes it from here,
 * so a platform is spelled the same in the queue, on the channels page and
 * in the brief.
 */
final class PlatformLabel
{
    /** Names that are not the platform key with a capital letter. */
    private const SPELLED = ['linkedin' => 'LinkedIn'];

    public static function for(string $platform): string
    {
        return self::SPELLED[$platform] ?? Str::headline($platform);
    }
}
