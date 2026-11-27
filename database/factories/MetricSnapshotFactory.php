<?php

namespace Database\Factories;

use App\Domain\Content\Models\PostVariant;
use App\Domain\Insights\Models\MetricSnapshot;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Create inside a workspace. Without ->for($variant, 'variant') it makes a
 * new published variant. Written directly, for tests and seeders only;
 * application code stores numbers with MetricSnapshot::record().
 *
 * The default row has reactions, comments and shares, and no clicks or
 * reach: what a Facebook Page gives without the insights permission.
 *
 * @extends Factory<MetricSnapshot>
 */
class MetricSnapshotFactory extends Factory
{
    protected $model = MetricSnapshot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_variant_id' => PostVariant::factory()->published(),
            'captured_at' => now(),
            'checked_at' => now(),
            'reactions' => fake()->numberBetween(0, 200),
            'comments' => fake()->numberBetween(0, 40),
            'shares' => fake()->numberBetween(0, 20),
            'clicks' => null,
            'reach' => null,
            'is_latest' => true,
        ];
    }

    /**
     * @param  array{reactions?: int|null, comments?: int|null, shares?: int|null, clicks?: int|null, reach?: int|null}  $numbers
     */
    public function numbers(array $numbers): static
    {
        return $this->state(fn () => [
            'reactions' => null,
            'comments' => null,
            'shares' => null,
            'clicks' => null,
            'reach' => null,
            ...$numbers,
        ]);
    }

    public function capturedAt(DateTimeInterface $at): static
    {
        return $this->state(fn () => ['captured_at' => $at, 'checked_at' => $at]);
    }

    /**
     * An older row of the variant's history, not its newest.
     */
    public function superseded(): static
    {
        return $this->state(fn () => ['is_latest' => false]);
    }
}
