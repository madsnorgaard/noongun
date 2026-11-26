<?php

namespace Database\Factories;

use App\Domain\Content\Models\Media;
use App\Domain\Content\Models\MediaAttachment;
use App\Domain\Content\Models\PostVariant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Create inside a workspace. Without ->for() it makes a new variant on a
 * new draft and a new image row. An attachment can only be added while the
 * post is a draft; add it before the state of the post is set.
 *
 * @extends Factory<MediaAttachment>
 */
class MediaAttachmentFactory extends Factory
{
    protected $model = MediaAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_variant_id' => PostVariant::factory(),
            'media_id' => Media::factory(),
            'position' => 1,
            'alt_text' => null,
        ];
    }

    public function described(string $altText = 'A harbour at dusk.'): static
    {
        return $this->state(fn () => ['alt_text' => $altText]);
    }

    /**
     * Already sent to the platform. Written directly, for tests only.
     */
    public function uploaded(?string $externalId = null): static
    {
        return $this->afterCreating(function (MediaAttachment $attachment) use ($externalId): void {
            DB::table('post_variant_media')->where('id', $attachment->id)->update([
                'external_id' => $externalId ?? 'media_ext_'.$attachment->id,
                'uploaded_at' => now(),
            ]);

            $attachment->refresh();
        });
    }
}
