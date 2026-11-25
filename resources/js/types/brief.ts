export type VoiceRules = {
    general: string[];
    platforms: Record<string, string[]>;
};

export type CallToAction = {
    text: string;
    url: string | null;
};

export type BriefVersionSummary = {
    version: number;
    is_current: boolean;
    saved_at: string | null;
    saved_by: string | null;
};

export type Brief = BriefVersionSummary & {
    title: string;
    body: string;
    voice_rules: VoiceRules;
    calls_to_action: CallToAction[];
};

export type BriefPlatform = {
    name: string;
    label: string;
};

export type BriefLimits = {
    title: number;
    body: number;
    rules: number;
    rule: number;
    calls_to_action: number;
    call: number;
};

export type Pillar = {
    id: number;
    name: string;
    description: string | null;
    weekly_target: number;
    ideas_count: number;
    posts_count: number;
};

export type PillarOption = {
    id: number;
    name: string;
};

export type SlotChannel = {
    id: number;
    display_name: string;
    platform_label: string;
    offered: boolean;
    can_publish: boolean;
};

export type Slot = {
    id: number;
    channel_id: number;
    /** ISO weekday: 1 is Monday, 7 is Sunday. */
    weekday: number;
    /** A time on the clock of the workspace, "09:00". */
    time: string;
    /** The next time the slot falls, as an instant. */
    next_at: string;
};

export type Idea = {
    id: number;
    title: string;
    pillar_id: number | null;
    pillar_name: string | null;
    source_url: string | null;
    notes: string | null;
    posts_count: number;
    created_at: string | null;
};
