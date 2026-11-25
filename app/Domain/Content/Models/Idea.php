<?php

namespace App\Domain\Content\Models;

use App\Domain\Workspaces\Concerns\BelongsToWorkspace;
use App\Domain\Workspaces\Policies\ContentPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\IdeaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A topic before it becomes posts: a title, optionally a source to write
 * from, notes and the pillar it belongs to.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $pillar_id
 * @property string $title
 * @property string|null $source_url
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Pillar|null $pillar
 */
#[Fillable(['pillar_id', 'title', 'source_url', 'notes'])]
#[UseFactory(IdeaFactory::class)]
#[UsePolicy(ContentPolicy::class)]
class Idea extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<IdeaFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Pillar, $this>
     */
    public function pillar(): BelongsTo
    {
        return $this->belongsTo(Pillar::class);
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
