export type ChannelStatus =
    | 'active'
    | 'pending'
    | 'needs_reconnect'
    | 'disconnected';

export type Channel = {
    id: number;
    platform: string;
    platform_label: string;
    display_name: string;
    external_id: string;
    status: ChannelStatus;
    token_expires_at: string | null;
    token_expired: boolean;
    token_expires_soon: boolean;
};

export type ChannelPlatform = {
    name: string;
    label: string;
    missing_settings: string[];
    /** What else to do in the platform's own app, as sentences. */
    setup_steps: string[];
    redirect_uri: string | null;
};

export type SkippedAccount = {
    id: string;
    name: string;
    reason: string;
};

export type ConnectReport = {
    platform_label: string;
    skipped: SkippedAccount[];
    hint: string | null;
};
