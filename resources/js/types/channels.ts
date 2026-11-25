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
    redirect_uri: string | null;
};
