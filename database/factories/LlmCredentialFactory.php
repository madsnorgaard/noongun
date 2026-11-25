<?php

namespace Database\Factories;

use App\Domain\Drafting\Models\LlmCredential;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Create inside a workspace: inWorkspace($workspace, fn () => LlmCredential::factory()->create()).
 * The key is random and belongs to no provider.
 *
 * @extends Factory<LlmCredential>
 */
class LlmCredentialFactory extends Factory
{
    protected $model = LlmCredential::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'fake',
            'api_key' => 'test-llm-key-'.Str::random(32),
        ];
    }

    public function provider(string $provider): static
    {
        return $this->state(fn () => ['provider' => $provider]);
    }
}
