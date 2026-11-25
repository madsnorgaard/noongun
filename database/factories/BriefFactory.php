<?php

namespace Database\Factories;

use App\Domain\Drafting\Models\Brief;
use App\Domain\Drafting\VoiceRules;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Create inside a workspace: inWorkspace($workspace, fn () => Brief::factory()->create()).
 *
 * Every brief that is created becomes the next version and the current one,
 * as in the application.
 *
 * @extends Factory<Brief>
 */
class BriefFactory extends Factory
{
    protected $model = Brief::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->company(),
            'body' => fake()->paragraphs(2, true),
            'voice_rules' => new VoiceRules,
            'calls_to_action' => [],
        ];
    }

    /**
     * The voice rules of the first workspace, from the project brief.
     */
    public function withHouseVoice(): static
    {
        return $this->state(fn () => [
            'voice_rules' => new VoiceRules(
                general: ['No contractions.', 'Short hyphens instead of em dashes.'],
                platforms: [
                    'facebook' => ['Personal and conversational, like old school Facebook. No hashtag walls.'],
                    'linkedin' => ['Professional but human.'],
                ],
            ),
        ]);
    }
}
