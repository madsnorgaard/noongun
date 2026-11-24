import { usePage } from '@inertiajs/vue3';
import type { ComputedRef } from 'vue';
import { computed } from 'vue';
import type { Post, PostState } from '@/types/posts';

export const stateLabels: Record<PostState, string> = {
    draft: 'Draft',
    approved: 'Approved',
    scheduled: 'Scheduled',
    publishing: 'Publishing',
    published: 'Published',
    failed: 'Failed',
    rejected: 'Rejected',
};

/**
 * The timezone of the current workspace. Every time on a page is shown in it.
 */
export function useWorkspaceTimezone(): ComputedRef<string> {
    const page = usePage();

    return computed(
        () =>
            (page.props.workspace as { timezone: string } | null)?.timezone ??
            'UTC',
    );
}

/**
 * A stored instant as a date and a time on the clock of the workspace.
 */
export function formatDateTime(instant: string, timezone: string): string {
    return new Intl.DateTimeFormat(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
        timeZone: timezone,
    }).format(new Date(instant));
}

/**
 * A calendar date ("2026-11-25") in words. The date is already local to the
 * workspace, so it is formatted as it is, without any shift.
 */
export function formatDate(
    date: string,
    options: Intl.DateTimeFormatOptions,
): string {
    return new Intl.DateTimeFormat(undefined, {
        ...options,
        timeZone: 'UTC',
    }).format(new Date(`${date}T12:00:00Z`));
}

/**
 * The length of a text the way the server counts it: in characters, not in
 * UTF-16 units, so that an emoji counts once.
 */
export function textLength(text: string): number {
    return Array.from(text).length;
}

/**
 * Every readiness problem of a post as one flat list.
 */
export function problemList(post: Post): string[] {
    return Object.values(post.problems).flat();
}

/**
 * An address that came from outside is only made a link when it is a web
 * address.
 */
export function webAddress(url: string | null): string | null {
    return url !== null && /^https?:\/\//i.test(url) ? url : null;
}
