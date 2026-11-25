<?php

namespace App\Http\Controllers\Channels\Concerns;

use App\Domain\Channels\Models\Channel;
use App\Http\Presenters\PlatformLabel;
use Inertia\Inertia;

trait PresentsChannels
{
    /** A token that ends within this many days is shown as ending soon. */
    private const EXPIRES_SOON_DAYS = 14;

    /**
     * What the channel pages show. Never includes a token.
     *
     * @return array{id: int, platform: string, platform_label: string, display_name: string, external_id: string, status: string, token_expires_at: string|null, token_expired: bool, token_expires_soon: bool}
     */
    protected function present(Channel $channel): array
    {
        return [
            'id' => $channel->id,
            'platform' => $channel->platform,
            'platform_label' => $this->platformLabel($channel->platform),
            'display_name' => $channel->display_name,
            'external_id' => $channel->external_id,
            'status' => $channel->status->value,
            'token_expires_at' => $channel->token_expires_at?->toIso8601String(),
            'token_expired' => $channel->tokenExpired(),
            'token_expires_soon' => $channel->tokenExpiresWithin(self::EXPIRES_SOON_DAYS),
        ];
    }

    protected function platformLabel(string $platform): string
    {
        return PlatformLabel::for($platform);
    }

    /**
     * @param  'success'|'info'|'warning'|'error'  $type
     */
    protected function toast(string $type, string $message): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
    }
}
