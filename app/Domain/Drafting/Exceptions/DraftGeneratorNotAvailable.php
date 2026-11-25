<?php

namespace App\Domain\Drafting\Exceptions;

use RuntimeException;

/**
 * DraftGenerators could not give a generator or a context for a provider:
 * it is not configured, has no model, or its class is missing or is not a
 * DraftGenerator. A configuration mistake, for the operator to fix.
 *
 * The message names classes, configuration paths and environment
 * variables. It is for the log. A member of a workspace is shown
 * FOR_MEMBERS instead.
 */
class DraftGeneratorNotAvailable extends RuntimeException
{
    public const FOR_MEMBERS = 'Drafting is not set up on this instance. The person who runs the instance can find the reason in the log.';

    /**
     * For the one cause a workspace puts right itself: it chose a provider
     * that the instance no longer offers.
     */
    public const CHOICE_NOT_OFFERED = 'The provider this workspace chose for drafting is no longer offered on this instance. An owner of this workspace can choose another one under Settings, Drafting.';

    private bool $choiceNotOffered = false;

    /**
     * The sentence a member of the workspace reads instead of the message.
     */
    public function forMembers(): string
    {
        return $this->choiceNotOffered ? self::CHOICE_NOT_OFFERED : self::FOR_MEMBERS;
    }

    /**
     * Whether an owner of the workspace can put it right by choosing a
     * provider, without the operator.
     */
    public function isChoiceNotOffered(): bool
    {
        return $this->choiceNotOffered;
    }

    public static function notConfigured(string $provider): self
    {
        return new self("No draft generator is configured for provider [{$provider}]. Add it to config/noongun.php under drafting.providers.");
    }

    public static function noDefaultProvider(): self
    {
        return new self('No default drafting provider is set. Set NOONGUN_DRAFT_PROVIDER.');
    }

    public static function notOffered(string $provider, int $workspaceId): self
    {
        $unavailable = new self("Workspace #{$workspaceId} chose provider [{$provider}], which this instance does not offer. Add it to NOONGUN_DRAFT_PROVIDERS, or an owner of the workspace chooses another provider under Settings, Drafting.");
        $unavailable->choiceNotOffered = true;

        return $unavailable;
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
