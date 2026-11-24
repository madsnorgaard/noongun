<?php

namespace App\Domain\Scheduling;

/**
 * What became of one variant in one run of PublishPostJob.
 */
enum AttemptOutcome
{
    /** On its platform (now or already), or gone. Nothing left to do. */
    case Settled;

    /** Failed for good in this round. Not tried again without a human. */
    case Failed;

    /** Failed in a way that may pass. Tried again after a wait. */
    case RetryLater;

    /**
     * Another process holds the variant, or the post is no longer in this
     * round of publishing. This job stops and leaves the post alone.
     */
    case NotOurs;
}
