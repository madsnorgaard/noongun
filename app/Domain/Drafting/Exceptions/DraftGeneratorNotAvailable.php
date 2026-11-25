<?php

namespace App\Domain\Drafting\Exceptions;

use RuntimeException;

/**
 * DraftGenerators could not give a generator or a context for a provider:
 * it is not configured, has no model, or its class is missing or is not a
 * DraftGenerator. A configuration mistake, for the operator to fix.
 */
class DraftGeneratorNotAvailable extends RuntimeException
{
    public static function notConfigured(string $provider): self
    {
        return new self("No draft generator is configured for provider [{$provider}]. Add it to config/noongun.php under drafting.providers.");
    }

    public static function noDefaultProvider(): self
    {
        return new self('No default drafting provider is set. Set NOONGUN_DRAFT_PROVIDER.');
    }

    public static function modelMissing(string $provider): self
    {
        return new self("No model is configured for provider [{$provider}]. Set it in config/noongun.php under drafting.providers.{$provider}.model.");
    }

    public static function classMissing(string $provider, string $class): self
    {
        return new self("The draft generator for provider [{$provider}] is configured as [{$class}], but that class does not exist.");
    }

    public static function notAGenerator(string $provider, string $class): self
    {
        return new self("The draft generator for provider [{$provider}] is [{$class}], which does not implement DraftGenerator.");
    }
}
