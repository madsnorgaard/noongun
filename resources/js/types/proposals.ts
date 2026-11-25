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
    /** The Monday of the week, as a calendar date. */
    week: string;
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
    short: { pillar: string; missing: number }[];
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
