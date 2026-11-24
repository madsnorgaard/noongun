<?php

namespace App\Domain\Scheduling\Events;

/**
 * A post left the publishing state as failed: at least one variant is not on
 * its platform. Fired by PublishPostJob and by the recovery of interrupted
 * posts. Nothing listens yet; the "publish failed" alert (M7) hooks in here.
 */
final readonly class PostFailedToPublish
{
    public function __construct(
        public int $workspaceId,
        public int $postId,
    ) {}
}
