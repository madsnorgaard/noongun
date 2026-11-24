<?php

namespace App\Domain\Content;

/**
 * The life of a post. This enum is the only place that says which
 * transitions are legal; Post applies them through its transition methods.
 * See docs/adr/0009-post-state-machine.md.
 */
enum PostState: string
{
    /** Being written. The only state in which a post and its variants can be edited. */
    case Draft = 'draft';

    /** A human has signed off the content. Not in the publishing queue yet. */
    case Approved = 'approved';

    /** Approved and committed to scheduled_at. The only state the scheduler picks up. */
    case Scheduled = 'scheduled';

    /** Claimed by the scheduler. Its variants are being sent to their platforms. */
    case Publishing = 'publishing';

    /** Every variant is on its platform. Final. */
    case Published = 'published';

    /** Publishing ended with at least one variant not on its platform. */
    case Failed = 'failed';

    /** A human turned the draft down. Kept for reference, can be reopened. */
    case Rejected = 'rejected';

    /**
     * The states this state may move to.
     *
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Draft => [self::Approved, self::Rejected],
            self::Approved => [self::Scheduled, self::Draft],
            self::Scheduled => [self::Publishing, self::Approved, self::Draft],
            self::Publishing => [self::Published, self::Failed],
            self::Published => [],
            self::Failed => [self::Scheduled, self::Draft],
            self::Rejected => [self::Draft],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->transitions(), true);
    }

    /**
     * Whether the post and its variants may be edited.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether a post in this state carries a human approval. Every state on
     * the way to a platform does; the database enforces it.
     */
    public function requiresApproval(): bool
    {
        return ! in_array($this, [self::Draft, self::Rejected], true);
    }

    public function isFinal(): bool
    {
        return $this->transitions() === [];
    }
}
