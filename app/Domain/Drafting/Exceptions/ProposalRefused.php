<?php

namespace App\Domain\Drafting\Exceptions;

use RuntimeException;

/**
 * A week was not proposed, or one post was not drafted from an idea, and
 * nothing was created or spent. The message
 * tells the person why and what to do. $reason names the cause for code
 * that wants to link to the place where it is fixed.
 */
class ProposalRefused extends RuntimeException
{
    public const BRIEF_MISSING = 'brief';

    public const NO_CHANNEL = 'channels';

    public const NO_SLOT = 'slots';

    public const NO_KEY = 'key';

    public const NOT_CONFIGURED = 'provider';

    /** The provider the workspace chose is no longer offered; an owner chooses another. */
    public const CHOICE_NOT_OFFERED = 'provider_choice';

    public const NOTHING_TO_DRAFT = 'empty';

    public const PLAN_CHANGED = 'changed';

    public const IN_PROGRESS = 'in_progress';

    public const DAILY_LIMIT = 'daily_limit';

    public const INSTANCE_LIMIT = 'instance_limit';

    /** One post from an idea: the channels that were ticked cannot all be drafted for. */
    public const CHANNELS_CHOSEN = 'channels_chosen';

    /** One post from an idea: the planned time that was given makes no sense. */
    public const PLANNED_TIME = 'planned_time';

    final public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function nothingToDraft(): static
    {
        return new static(
            self::NOTHING_TO_DRAFT,
            (string) __('There is nothing to draft in this week: every slot time has passed, is too close or already has a post.'),
        );
    }

    public static function because(string $reason, string $message): static
    {
        return new static($reason, $message);
    }
}
