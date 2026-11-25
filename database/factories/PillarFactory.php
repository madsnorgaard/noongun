<?php

namespace Database\Factories;

use App\Domain\Content\Models\Pillar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Create inside a workspace: inWorkspace($workspace, fn () => Pillar::factory()->create()).
 *
 * @extends Factory<Pillar>
 */
class PillarFactory extends Factory
{
    protected $model = Pillar::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->word()).' '.fake()->word(),
            'description' => fake()->sentence(),
            'weekly_target' => 1,
        ];
    }

    public function weeklyTarget(int $posts): static
    {
        return $this->state(fn () => ['weekly_target' => $posts]);
    }
}
