<?php

namespace Database\Factories;

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Models\Channel;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Create inside a workspace: inWorkspace($workspace, fn () => Channel::factory()->create()).
 *
 * @extends Factory<Channel>
 */
class ChannelFactory extends Factory
{
    protected $model = Channel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'platform' => 'facebook',
            'external_id' => (string) fake()->unique()->numerify('1##############'),
            'display_name' => fake()->company(),
            'access_token' => 'test-access-'.Str::random(40),
            'refresh_token' => null,
            'token_expires_at' => null,
            'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'],
            'status' => ChannelStatus::Active,
        ];
    }

    public function platform(string $platform): static
    {
        return $this->state(fn () => ['platform' => $platform]);
    }

    public function expiresAt(DateTimeInterface $at): static
    {
        return $this->state(fn () => ['token_expires_at' => $at]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['token_expires_at' => now()->subDay()]);
    }

    public function withRefreshToken(): static
    {
        return $this->state(fn () => ['refresh_token' => 'test-refresh-'.Str::random(40)]);
    }

    public function needsReconnect(): static
    {
        return $this->state(fn () => ['status' => ChannelStatus::NeedsReconnect]);
    }

    /**
     * Returned by a connect and not confirmed by the user yet.
     */
    public function pending(): static
    {
        return $this->state(fn () => ['status' => ChannelStatus::Pending]);
    }

    public function disconnected(): static
    {
        return $this->state(fn () => [
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'status' => ChannelStatus::Disconnected,
        ]);
    }
}
