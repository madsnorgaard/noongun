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
 * - never: it grew too old without a reading
 * - unavailable: its platform gives no numbers
 * - off: insights are turned off on this instance
 * - blocked: its channel is not connected, so it is not read
 */
export type MeasuringState =
    | 'measured'
    | 'pending'
    | 'never'
    | 'unavailable'
    | 'off'
    | 'blocked';

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
    score: number | null;
    checked_at: string | null;
    next_reading_at: string | null;
    /** The platform was asked at least once. */
    asked: boolean;
    /** Too old to be read any more. */
    stopped: boolean;
    /** Young enough to be read, and its channel is not connected. */
    stale: boolean;
    needs_permission: MetricName[];
    /** Only on the post page. */
    history: MetricReadingRow[] | null;
};

export type PostInsights = {
    variants: VariantInsights[];
    total: {
        numbers: MetricNumbers;
        score: number | null;
        measured: number;
        published: number;
        checked_at: string | null;
    } | null;
};

export type Reading = {
    enabled: boolean;
    /** How often a post is read, as a sentence. */
    cadence: string;
    /** The age after which a post is not read any more, for example "21 days". */
    stop_after: string;
};

export type SummaryNumber = {
    total: number | null;
    /** Over the posts that have the number, not over all measured posts. */
    average: number | null;
    posts: number;
};

export type Summary = {
    posts: number;
    measured_posts: number;
    scored_posts: number;
    numbers: Record<MetricName, SummaryNumber>;
    score: number | null;
    average_score: number | null;
};

export type PillarSummary = Summary & {
    /** Null for the posts without a pillar. */
    id: number | null;
    name: string | null;
};

export type ChannelSummary = Summary & {
    id: number;
    name: string;
    platform_label: string;
    status: ChannelStatus;
    gives_numbers: boolean;
    metrics: MetricName[];
    needs_permission: MetricName[];
    paused: boolean;
};

export type TopPost = {
    post_id: number;
    excerpt: string;
    pillar: string | null;
    channels: { name: string; platform_label: string }[];
    published_at: string | null;
    numbers: MetricNumbers;
    score: number | null;
    measured: number;
    published: number;
};

export type PeriodTotals = {
    posts: number;
    measured_posts: number;
    numbers: MetricNumbers;
    score: number | null;
};

export type ScoreWeights = Record<Exclude<MetricName, 'reach'>, number>;

export type InsightsStatus =
    | 'ok'
    | 'nothing_published'
    | 'nothing_in_period'
    | 'nothing_measured';

export type DashboardInsights = PeriodTotals & {
    days: number;
    best: TopPost | null;
};
