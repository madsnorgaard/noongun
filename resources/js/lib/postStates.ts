import type { PostState } from '@/types/posts';

/**
 * How each state of a post is drawn: the fill of its chip and the dot in it.
 * Draft, Approved, Published and Failed follow the guide. Scheduled,
 * Publishing and Rejected are states the guide does not draw; they use the
 * same shapes. docs/design.md has the table.
 */
export const stateChips: Record<PostState, string> = {
    draft: 'bg-secondary text-secondary-foreground',
    approved: 'bg-card text-foreground ring-[1.5px] ring-foreground ring-inset',
    scheduled: 'bg-primary text-primary-foreground',
    publishing: 'bg-primary text-primary-foreground',
    published: 'bg-success-fill text-white',
    failed: 'bg-destructive-fill text-white',
    rejected: 'bg-secondary text-muted-foreground',
};

/** The dot inside a chip, which sits on the fill above. */
export const stateChipDots: Record<PostState, string> = {
    draft: 'rounded-full bg-idle',
    approved: 'rounded-full ring-2 ring-foreground ring-inset',
    scheduled: 'rounded-full bg-brass dark:bg-warning-fill',
    publishing: 'animate-ng-pulse rounded-full bg-brass dark:bg-warning-fill',
    published: 'rounded-full bg-white',
    failed: 'rounded-[2px] bg-white',
    rejected: 'rounded-full ring-2 ring-idle ring-inset',
};

/** The same dot on a plain surface, next to the word instead of in a chip. */
export const stateDots: Record<PostState, string> = {
    draft: 'rounded-full bg-idle',
    approved: 'rounded-full ring-2 ring-foreground ring-inset',
    scheduled: 'rounded-full bg-brass',
    publishing: 'animate-ng-pulse rounded-full bg-brass',
    published: 'rounded-full bg-success',
    failed: 'rounded-[2px] bg-destructive',
    rejected: 'rounded-full ring-2 ring-idle ring-inset',
};
