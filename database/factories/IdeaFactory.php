<?php

namespace Database\Factories;

use App\Domain\Content\Models\Idea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Create inside a workspace: inWorkspace($workspace, fn () => Idea::factory()->create()).
 * Without a pillar unless one is given: ->for($pillar).
 *
 * @extends Factory<Idea>
 */
class IdeaFactory extends Factory
{
    protected $model = Idea::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pillar_id' => null,
            'title' => fake()->sentence(4),
            'source_url' => null,
            'notes' => null,
        ];
    }

    public function withSource(string $url = 'https://example.com/article'): static
    {
        return $this->state(fn () => ['source_url' => $url]);
    }
}
