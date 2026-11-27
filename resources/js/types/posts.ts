export type PostState =
    | 'draft'
    | 'approved'
    | 'scheduled'
    | 'publishing'
    | 'published'
    | 'failed'
    | 'rejected';

/** A stored image. The addresses are private and relative to this instance. */
export type MediaItem = {
    id: number;
    url: string;
    /** A small copy, for lists. */
    thumb_url: string;
    mime: string;
    byte_size: number;
    width: number;
    height: number;
};

/** An image on a text, with the description typed for that channel. */
export type AttachedMedia = MediaItem & { alt_text: string | null };

/** An image on a text as the editor sends it. */
export type MediaInput = { id: number; alt_text: string };

/** What "Copy from" brings over from the column of another channel. */
export type CopyKind = 'text' | 'images' | 'both';

/** What a platform takes in images. `max_items` is 0 when it takes none. */
export type MediaRules = {
    max_items: number;
    types: string[];
    max_bytes: number | null;
    max_pixels: number | null;
    /** Null when the platform takes no description for an image. */
    alt_text_length: number | null;
    /** What becomes of the link of a text that has images. */
    link_rule: 'in_text' | 'both' | 'refused';
};

/** Where an upload goes and what this instance takes. */
export type UploadRules = {
    url: string;
    field: string;
    accept: string[];
    max_bytes: number;
    max_megapixels: number;
    max_side: number;
    max_per_variant: number;
    alt_text_length: number;
};

export type PostVariant = {
    id: number;
    channel_id: number;
    channel_name: string;
    platform_label: string;
    max_body_length: number | null;
    body: string;
    link: string | null;
    /** The card for the link on a platform that builds none itself. */
    link_title: string | null;
    link_description: string | null;
    /** The link with its campaign parameters, as the platform gets it. */
    link_as_sent: string | null;
    /** The images in the order they are shown. */
    media: AttachedMedia[];
    media_rules: MediaRules;
    published: boolean;
    /** A platform was called for it and nothing says how that ended. */
    outcome_unknown: boolean;
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
    /** Worth a look, keyed like the problems. Never holds an approval back. */
    advice: Record<string, string[]>;
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
    /** Channels the draft was planned for but could not be written for. */
    left_out: string[];
    /** How many approved posts were sent along as examples of the voice. */
    examples: number;
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
    /** Set when the platform shows a link only as the card the author types. */
    link_title_length: number | null;
    link_description_length: number | null;
    media: MediaRules;
};

export type CalendarEntry = {
    id: number;
    state: PostState;
    time: string;
    channels: string[];
    excerpt: string;
    /** The small copy of the first image of the first text. */
    thumb_url: string | null;
    /** The description typed for that image, or null without one. */
    thumb_alt: string | null;
    /** How many images the first text has. */
    image_count: number;
};

export type CalendarDay = {
    date: string;
    in_period: boolean;
    today: boolean;
    posts: CalendarEntry[];
};
