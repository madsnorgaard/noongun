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
    /** Where a drafted post came from. Null for one written by hand. */
    origin: PostOrigin | null;
    can: PostAbilities;
};

/**
 * What the draft run of a drafted post recorded. The rationale and the
 * alternative text are what a model wrote: show them as text only.
 */
export type PostOrigin = {
    pillar: string | null;
    idea: string | null;
    provider: string;
    model: string;
    rationale: string | null;
    alt_text: string | null;
    /** The model suggested a link the author never gave, and it was left out. */
    link_dropped: boolean;
    drafted_at: string | null;
    /** Tokens the draft used, as the provider reported them. */
    input_tokens: number | null;
    output_tokens: number | null;
    /** Estimated cost by the price table, to four places, or null without a price. */
    cost: string | null;
    currency: string | null;
};

export type ChannelOption = {
    id: number;
    display_name: string;
    platform_label: string;
    can_publish: boolean;
    problem: string | null;
    max_body_length: number | null;
    /** Whether the platform shows a link as a preview card. Null when unknown. */
    link_previews: boolean | null;
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
