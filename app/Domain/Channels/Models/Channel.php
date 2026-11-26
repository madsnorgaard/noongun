<?php

namespace App\Domain\Channels\Models;

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Events\ChannelConnected;
use App\Domain\Channels\Policies\ChannelPolicy;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Workspaces\Concerns\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Database\Factories\ChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SensitiveParameter;

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
#[UsePolicy(ChannelPolicy::class)]
class Channel extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<ChannelFactory> */
    use HasFactory;

    /**
     * How long a pending channel waits for the user. After that it is
     * discarded by discardExpiredPending(), which the scheduler runs.
     */
    public const PENDING_MINUTES = 15;

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

    /**
     * Saves what a connect returned for one account, for adapters to call
     * from ChannelAdapter::connect(). It decides the status, so no adapter
     * can make an account publishable that the user has not confirmed:
     *
     * - A channel that is active or needs a reconnect was chosen by the user
     *   and not disconnected since. It is renewed and is active at once, so
     *   it never stops publishing while the user is asked about others.
     * - A new account, a disconnected one and one that is still pending are
     *   saved as pending. They hold the token but cannot publish until
     *   confirm() is called for them.
     *
     * @param  array{display_name: string, access_token: string, refresh_token?: string|null, token_expires_at?: CarbonImmutable|null, scopes?: list<string>}  $attributes
     */
    public static function recordConnection(string $platform, string $externalId, #[SensitiveParameter] array $attributes): static
    {
        $existing = static::query()->where('platform', $platform)->where('external_id', $externalId)->first();

        $channel = static::query()->updateOrCreate(
            ['platform' => $platform, 'external_id' => $externalId],
            [
                'refresh_token' => null,
                'token_expires_at' => null,
                'scopes' => [],
                ...$attributes,
                'status' => $existing?->status->isConfirmed() ? ChannelStatus::Active : ChannelStatus::Pending,
            ],
        );

        if ($channel->status === ChannelStatus::Active) {
            event(new ChannelConnected($channel->workspace_id, $channel->id));
        }

        return $channel;
    }

    public function isPending(): bool
    {
        return $this->status === ChannelStatus::Pending;
    }

    /**
     * The user keeps a pending channel: it becomes active. One UPDATE that
     * only matches a row that is still pending, so a channel that was
     * discarded in the meantime is not brought back. Returns whether this
     * call activated it.
     */
    public function confirm(): bool
    {
        static::guardWorkspace($this);

        $confirmed = static::query()
            ->whereKey($this->getKey())
            ->where('status', ChannelStatus::Pending)
            ->whereNotNull('access_token')
            ->update(['status' => ChannelStatus::Active, 'updated_at' => now()]) === 1;

        // The row may be gone if it was discarded in the meantime.
        if ($confirmed) {
            $this->refresh();

            event(new ChannelConnected($this->workspace_id, $this->id));
        }

        return $confirmed;
    }

    /**
     * The user declined a pending channel, or did not answer in time. A
     * channel with post variants goes back to disconnected, which erases the
     * token and keeps the history. One without is deleted, so an account
     * that was never confirmed leaves nothing behind. Does nothing to a
     * channel that is not pending.
     */
    public function discardPending(): void
    {
        if (! $this->isPending()) {
            return;
        }

        if ($this->variants()->exists()) {
            $this->disconnect();

            return;
        }

        $this->delete();
    }

    /**
     * Pending channels of the current workspace that have waited longer than
     * PENDING_MINUTES.
     *
     * @param  Builder<Channel>  $query
     */
    #[Scope]
    protected function expiredPending(Builder $query): void
    {
        $query->where('status', ChannelStatus::Pending)
            ->where('updated_at', '<=', now()->subMinutes(self::PENDING_MINUTES));
    }

    /**
     * Discards the expired pending channels of the current workspace and
     * returns how many there were.
     */
    public static function discardExpiredPending(): int
    {
        return static::query()->expiredPending()->get()
            ->each(fn (Channel $channel) => $channel->discardPending())
            ->count();
    }
}
