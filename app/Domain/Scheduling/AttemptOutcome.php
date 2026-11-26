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
     * Nothing failed, but the variant has more images to send than one run
     * may. The next run carries on at once from what is remembered.
     */
    case MoreToUpload;

    /**
     * Another process holds the variant, or the post is no longer in this
     * round of publishing. This job stops and leaves the post alone.
     */
    case NotOurs;
}
