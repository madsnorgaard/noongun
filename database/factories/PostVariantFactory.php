<?php

namespace Database\Factories;

use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostVariant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Create inside a workspace. Without ->for($post) or ->for($channel) it
 * makes a new draft post and a new channel. A variant can only be added to
 * a draft; for later states see PostFactory::withVariants().
 *
 * @extends Factory<PostVariant>
 */
class PostVariantFactory extends Factory
{
    protected $model = PostVariant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'channel_id' => Channel::factory(),
            'body' => fake()->paragraph(),
            'link' => null,
            'utm' => null,
        ];
    }

    public function withLink(string $link = 'https://example.com/article'): static
    {
        return $this->state(fn () => [
            'link' => $link,
            'utm' => ['utm_source' => 'facebook', 'utm_medium' => 'social'],
        ]);
    }

    /**
     * Already on its platform. Written directly, for tests and seeders only.
     */
    public function published(?string $externalId = null): static
    {
        return $this->afterCreating(function (PostVariant $variant) use ($externalId): void {
            $externalId ??= 'ext_'.$variant->id;

            DB::table('post_variants')->where('id', $variant->id)->update([
                'external_id' => $externalId,
                'permalink' => 'https://platform.test/posts/'.$externalId,
                'published_at' => now(),
                'error' => null,
            ]);

            $variant->refresh();
        });
    }

    /**
     * Carrying an error from a failed attempt. Written directly.
     */
    public function failed(string $error = 'The platform refused the post.'): static
    {
        return $this->afterCreating(function (PostVariant $variant) use ($error): void {
            DB::table('post_variants')->where('id', $variant->id)->update(['error' => $error]);

            $variant->refresh();
        });
    }
}
