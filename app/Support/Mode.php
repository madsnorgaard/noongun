<?php

namespace App\Support;

enum Mode: string
{
    case SelfHosted = 'self_hosted';
    case Saas = 'saas';

    public static function current(): self
    {
        return self::from((string) config('noongun.mode'));
    }

    public static function isSelfHosted(): bool
    {
        return self::current() === self::SelfHosted;
    }
}
