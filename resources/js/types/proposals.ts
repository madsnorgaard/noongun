export type DraftRunStatus = 'pending' | 'running' | 'succeeded' | 'failed';

/** One planned post of a proposal that was started, as the queue shows it. */
export type ProposalRun = {
    id: number;
    status: DraftRunStatus;
    /** The instant in UTC. */
    planned_at: string | null;
    channels: string[];
    pillar: string | null;
    idea: string | null;
    /** Which try this is, from 1. */
    attempt: number;
    failure_reason: string | null;
    /** Why an earlier try failed, while this one waits or runs. */
    earlier_failure: string | null;
    post_id: number | null;
};

export type ProposalProgress = {
    /** A proposed week, or one post that was asked for on an idea. */
    kind: 'week' | 'idea';
    /** The Monday of the week, as a calendar date. Empty for one post from an idea. */
    week: string;
    /** The idea one post was asked for on, while it exists. */
    idea: { id: number; title: string } | null;
    requested_at: string | null;
    requested_by: string | null;
    /** Whether a draft is still waiting or being drafted. */
    active: boolean;
    /** Whether a draft has waited longer than a queue that runs would need. */
    waiting_long: boolean;
    counts: Record<DraftRunStatus, number>;
    /** What every attempt so far used. Null before the first answer. */
    used: {
        input_tokens: number;
        output_tokens: number;
        /** Estimated cost, to four places, or null without a price. */
        cost: string | null;
        currency: string | null;
    } | null;
    max_attempts: number;
    runs: ProposalRun[];
};

export type QueueDrafting = {
    can_propose: boolean;
    proposal: ProposalProgress | null;
};

export type PlannedPost = {
    /** The instant in UTC. */
    at: string;
    channels: { name: string; platform_label: string }[];
    pillar: string | null;
    idea: string | null;
};

export type WeekPlan = {
    /** Sent back when the proposal is started, so that it runs what was shown. */
    fingerprint: string;
    posts: PlannedPost[];
    past: number;
    /** Slot times closer than the lead time, which are left out too. */
    soon: number;
    lead_minutes: number;
    taken: number;
    unavailable: number;
    left_out: number;
    /** Posts written once for each of several channels that share a time. */
    shared: number;
    /** Posts for one channel only, in a plan that drafts for several. */
    for_one_channel: number;
    short: { pillar: string; missing: number }[];
    /** How many pillars have a weekly target above zero. */
    pillars_with_target: number;
};

export type ProposalRefusal = {
    reason: string;
    message: string;
    href: string | null;
    label: string | null;
};

export type ProposalWeek = {
    start: string;
    end: string;
    previous: string | null;
    next: string | null;
};

/** What "Draft a post" on an idea would do, for what is chosen now. */
export type IdeaDraftPlan = {
    /** Sent back when the draft is started, so that it runs what was shown. */
    fingerprint: string;
    idea: {
        id: number;
        title: string;
        notes: string | null;
        source_url: string | null;
        pillar: string | null;
        /** How many posts were already drafted from the idea. */
        used_in: number;
    };
    /** Every channel a post can be drafted for. */
    channels: {
        id: number;
        name: string;
        platform_label: string;
        chosen: boolean;
    }[];
    /** Wall-clock time of the workspace, or empty to decide after approval. */
    scheduled_at: string;
    problems: { channels: string[]; scheduled_at: string[] };
};
