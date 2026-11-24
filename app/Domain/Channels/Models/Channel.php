<?php

namespace App\Domain\Channels\Models;

use App\Domain\Channels\ChannelStatus;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Workspaces\Concerns\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Database\Factories\ChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One connected Page or profile on a platform.
 *
 * Tokens are encrypted at rest and hidden from arrays and JSON. Never put
 * them in a log line, an exception or a fixture.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $platform
 * @property string $external_id
 * @property string $display_name
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property CarbonImmutable|null $token_expires_at
 * @property list<string> $scopes
 * @property ChannelStatus $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['platform', 'external_id', 'display_name', 'access_token', 'refresh_token', 'token_expires_at', 'scopes', 'status'])]
#[Hidden(['access_token', 'refresh_token'])]
#[UseFactory(ChannelFactory::class)]
class Channel extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<ChannelFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => ChannelStatus::Active->value,
        'scopes' => '[]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
            'status' => ChannelStatus::class,
        ];
    }

    /**
     * @return HasMany<PostVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(PostVariant::class);
    }

    /**
     * True when the channel is active and holds a token that has not expired.
     * PublishPostJob checks this before it calls the adapter.
     */
    public function canPublish(): bool
    {
        return $this->status->canPublish()
            && filled($this->access_token)
            && ! $this->tokenExpired();
    }

    public function tokenExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    /**
     * True when the token has an expiry that falls within the given number
     * of days. A token without an expiry never does.
     */
    public function tokenExpiresWithin(int $days): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->lte(now()->addDays($days));
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * The platform refused the token. Publishing stops until a human
     * connects the channel again.
     */
    public function markNeedsReconnect(): static
    {
        $this->status = ChannelStatus::NeedsReconnect;
        $this->save();

        return $this;
    }

    /**
     * Erases the tokens and keeps the row, so that published variants keep
     * their channel. Connecting the same account again reuses the row.
     */
    public function disconnect(): static
    {
        $this->forceFill([
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'status' => ChannelStatus::Disconnected,
        ])->save();

        return $this;
    }
}
