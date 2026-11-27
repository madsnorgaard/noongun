<?php

namespace Database\Factories;

use App\Domain\Content\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Create inside a workspace. Makes the row only: no file is written. Tests
 * that read the file use storedImage() from tests/Support/media.php.
 *
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = bin2hex(random_bytes(20));

        return [
            'uploaded_by' => null,
            'disk' => 'media',
            'path' => "factory/{$name}.jpg",
            'thumb_path' => "factory/{$name}_thumb.jpg",
            'mime' => 'image/jpeg',
            'byte_size' => 20000,
            'width' => 1200,
            'height' => 800,
            'content_hash' => hash('sha256', $name),
        ];
    }

    public function png(): static
    {
        return $this->state(fn (array $attributes) => [
            'mime' => 'image/png',
            'path' => str_replace('.jpg', '.png', (string) $attributes['path']),
        ]);
    }

    public function sized(int $width, int $height, ?int $bytes = null): static
    {
        return $this->state(fn (array $attributes) => [
            'width' => $width,
            'height' => $height,
            'byte_size' => $bytes ?? $attributes['byte_size'],
        ]);
    }
}
