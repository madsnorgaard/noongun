<?php

namespace App\Domain\Content\Models;

use App\Domain\Workspaces\Concerns\BelongsToWorkspace;
use App\Domain\Workspaces\Policies\ContentPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\PillarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A content theme and its share of the week: weekly_target is how many
 * posts a proposed week should hold for it. Zero means none are planned.
 *
 * Deleting a pillar keeps its ideas and posts and clears their pillar.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string|null $description
 * @property int $weekly_target
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'description', 'weekly_target'])]
#[UseFactory(PillarFactory::class)]
#[UsePolicy(ContentPolicy::class)]
class Pillar extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<PillarFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'weekly_target' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekly_target' => 'integer',
        ];
    }

    /**
     * @return HasMany<Idea, $this>
     */
    public function ideas(): HasMany
    {
        return $this->hasMany(Idea::class);
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
