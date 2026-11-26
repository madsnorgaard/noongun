<?php

namespace App\Domain\Workspaces\Models;

use App\Domain\Workspaces\Policies\WorkspacePolicy;
use App\Domain\Workspaces\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The tenant. Every tenant-owned row points here through workspace_id.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $timezone
 * @property string $locale
 * @property string|null $drafting_provider the LLM provider its owner chose; null follows the instance
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'timezone', 'locale'])]
#[UseFactory(WorkspaceFactory::class)]
#[UsePolicy(WorkspacePolicy::class)]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace): void {
            if (blank($workspace->slug)) {
                $workspace->slug = static::uniqueSlug($workspace->name);
            }
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        $suffix = 2;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return BelongsToMany<User, $this, Membership, 'membership'>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships')
            ->using(Membership::class)
            ->as('membership')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function roleOf(User $user): ?Role
    {
        return $this->memberships()->where('user_id', $user->id)->first()?->role;
    }

    /**
     * Interpret a wall-clock time in this workspace and return it in UTC,
     * which is how it is stored.
     */
    public function toUtc(string|\DateTimeInterface $localTime): CarbonImmutable
    {
        if ($localTime instanceof \DateTimeInterface) {
            $localTime = $localTime->format('Y-m-d H:i:s');
        }

        return CarbonImmutable::parse($localTime, $this->timezone)->utc();
    }

    /**
     * Show a stored (UTC) time in this workspace's timezone.
     */
    public function toLocal(CarbonInterface $utcTime): CarbonImmutable
    {
        return CarbonImmutable::instance($utcTime)->setTimezone($this->timezone);
    }
}
