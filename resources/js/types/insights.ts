export type MetricName =
    | 'reactions'
    | 'comments'
    | 'shares'
    | 'clicks'
    | 'reach';

/** Null is a number the platform did not give. It is never zero. */
export type MetricNumbers = Record<MetricName, number | null>;

/**
 * Why a number is missing: the platform has no such number, the channel
 * was connected without the permission for it, or the platform has it and
 * did not give it for this post.
 */
export type MissingReason = 'platform' | 'permission' | 'not_given';

export type MetricCell = {
    value: number | null;
    missing: MissingReason | null;
};

export type ChannelStatus =
    | 'active'
    | 'pending'
    | 'needs_reconnect'
    | 'disconnected';

/**
 * - measured: it has numbers
 * - pending: a reading is still to come
 * - waiting_once: older than the cadence reaches and never read; it waits
 *   for the one reading that old posts get
 * - no_numbers: it was asked about and the platform gave nothing
 * - too_old: it went out too long ago to be read at all
 * - unavailable: its platform gives no numbers
 * - off: insights are turned off on this instance
 * - blocked: its channel is not connected, so it is not read
 * - gone: the platform says the post is not there any more
 */
export type MeasuringState =
    | 'measured'
    | 'pending'
    | 'waiting_once'
    | 'no_numbers'
    | 'too_old'
    | 'unavailable'
    | 'off'
    | 'blocked'
    | 'gone';

/**
 * Why reading is paused for a channel: the platform asked for a wait, it
 * did not accept the token for reading, a reading did not get through, or
 * only the fact of a pause is known.
 */
export type Pause = {
    kind: 'rate_limit' | 'token' | 'other' | 'unknown';
    /** When reading starts again, when that is known. */
    until: string | null;
};

export type MetricReadingRow = MetricNumbers & {
    /** The numbers held from here... */
    captured_at: string;
    /** ...until at least here. */
    checked_at: string;
};

export type VariantInsights = {
    variant_id: number;
    channel_id: number;
    channel_name: string;
    platform_label: string;
    channel_status: ChannelStatus;
    state: MeasuringState;
    numbers: Record<MetricName, MetricCell> | null;
    /** Reactions, comments and shares by their weights. Clicks do not count. */
    score: number | null;
    checked_at: string | null;
    next_reading_at: string | null;
    /** The platform was asked at least once. */
    asked: boolean;
    /** Too old to be read any more. */
    stopped: boolean;
    /** Young enough to be read, and its channel is not connected. */
    stale: boolean;
    /** Young enough to be read, and the platform says the post is gone. */
    gone: boolean;
    /** Set when a reading is expected and reading is paused. */
    pause: Pause | null;
    needs_permission: MetricName[];
    /** Only on the post page. */
    history: MetricReadingRow[] | null;
};

export type PostInsights = {
    variants: VariantInsights[];
    total: {
        /** Reach is null for several channels: it cannot be added up. */
        numbers: MetricNumbers;
        score: number | null;
        measured: number;
        published: number;
        checked_at: string | null;
    } | null;
};

export type ScoreWeights = Record<'reactions' | 'comments' | 'shares', number>;

export type Reading = {
    enabled: boolean;
    /** How often a post is read, as a sentence. */
    cadence: string;
    /** The age after which a post is not read any more, for example "21 days". */
    stop_after: string;
    /** How far back a post that was never read is still read once. */
    once_within: string;
    /** How old a post has to be before it is compared, for example "2 days". */
    min_age: string;
    /** How many posts have to compete before one is called the best. */
    min_measured: number;
    weights: ScoreWeights;
    /** Whether the viewer can connect channels and is told how to set things up. */
    can_set_up: boolean;
};

export type SummaryNumber = {
    /** Always null for reach: it cannot be added up over posts. */
    total: number | null;
    /** Over the channel posts that have the number. */
    average: number | null;
    with: number;
};

/** The unit is a channel post: one post on one channel. */
export type Summary = {
    posts: number;
    channel_posts: number;
    measured: number;
    scored: number;
    numbers: Record<MetricName, SummaryNumber>;
    score: number | null;
    average_score: number | null;
};

export type PillarSummary = Summary & {
    /** Null for the posts without a pillar. */
    id: number | null;
    name: string | null;
    /** False while too few of its posts are measured to compare it. */
    enough: boolean;
};

export type ChannelSummary = Summary & {
    id: number;
    name: string;
    platform_label: string;
    status: ChannelStatus;
    gives_numbers: boolean;
    metrics: MetricName[];
    needs_permission: MetricName[];
    pause: Pause | null;
};

export type TopPost = {
    post_id: number;
    excerpt: string;
    pillar: string | null;
    channels: { id: number; name: string; platform_label: string }[];
    published_at: string | null;
    numbers: MetricNumbers;
    score: number | null;
    measured: number;
    published: number;
};

export type PeriodTotals = {
    posts: number;
    channel_posts: number;
    measured: number;
    /** Reach is always null here. */
    numbers: MetricNumbers;
    /** How many measured channel posts each total is made of. */
    with: Record<MetricName, number>;
    score: number | null;
};

export type InsightsStatus =
    | 'ok'
    | 'nothing_published'
    | 'nothing_in_period'
    | 'nothing_measured';

export type DashboardInsights = PeriodTotals & {
    days: number;
    /** Null while too few posts compete to call one the best. */
    best: TopPost | null;
};
