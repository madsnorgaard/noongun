export type PostState =
    | 'draft'
    | 'approved'
    | 'scheduled'
    | 'publishing'
    | 'published'
    | 'failed'
    | 'rejected';

export type PostVariant = {
    id: number;
    channel_id: number;
    channel_name: string;
    platform_label: string;
    max_body_length: number | null;
    body: string;
    link: string | null;
    published: boolean;
    permalink: string | null;
    published_at: string | null;
    error: string | null;
};

export type PostAbilities = {
    update: boolean;
    approve: boolean;
    reject: boolean;
    schedule: boolean;
    unschedule: boolean;
    return_to_draft: boolean;
    retry: boolean;
    delete: boolean;
};

export type Post = {
    id: number;
    state: PostState;
    /** The instant in UTC. */
    scheduled_at: string | null;
    /** The same time on the clock of the workspace, for a time field. */
    scheduled_local: string | null;
    approval: { at: string; by: string | null } | null;
    updated_at: string | null;
    /** Fingerprint of the content, sent back when the post is approved. */
    version: string;
    variants: PostVariant[];
    /** Why the post cannot go out as it stands, keyed by field. */
    problems: Record<string, string[]>;
    can: PostAbilities;
};

export type ChannelOption = {
    id: number;
    display_name: string;
    platform_label: string;
    can_publish: boolean;
    problem: string | null;
    max_body_length: number | null;
};

export type CalendarEntry = {
    id: number;
    state: PostState;
    time: string;
    channels: string[];
    excerpt: string;
};

export type CalendarDay = {
    date: string;
    in_period: boolean;
    today: boolean;
    posts: CalendarEntry[];
};
