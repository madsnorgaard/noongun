<?php

namespace App\Domain\Content\Models;

use App\Domain\Content\Exceptions\ApprovalNotAllowed;
use App\Domain\Content\Exceptions\IllegalPostTransition;
use App\Domain\Content\Policies\PostPolicy;
use App\Domain\Content\PostState;
use App\Domain\Workspaces\Concerns\BelongsToWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\PostFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The cross-channel unit: one post, one variant per channel.
 *
 * state, approved_by and approved_at are not fillable and cannot be saved
 * directly. They change only through the transition methods below, each of
 * which is one conditional UPDATE, so two processes can never both win the
 * same transition. Only approve() leads to the approved state, and it needs
 * a User who may edit content in the post's workspace.
 *
 * @property int $id
 * @property int $workspace_id
 * @property PostState $state
 * @property CarbonImmutable|null $scheduled_at
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace|null $workspace
 */
#[Fillable(['scheduled_at'])]
#[UseFactory(PostFactory::class)]
#[UsePolicy(PostPolicy::class)]
class Post extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<PostFactory> */
    use HasFactory;

    /** Attributes that only the transition methods may change. */
    private const GUARDED_BY_TRANSITIONS = ['state', 'approved_by', 'approved_at'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'state' => PostState::Draft->value,
    ];

    protected static function booted(): void
    {
        static::creating(function (Post $post): void {
            if ($post->state !== PostState::Draft || $post->approved_by !== null || $post->approved_at !== null) {
                throw IllegalPostTransition::notStartedAsDraft();
            }
        });

        static::updating(function (Post $post): void {
            foreach (self::GUARDED_BY_TRANSITIONS as $attribute) {
                if ($post->isDirty($attribute)) {
                    throw IllegalPostTransition::direct($attribute);
                }
            }

            if ($post->isDirty('scheduled_at') && in_array($post->state, [PostState::Scheduled, PostState::Publishing, PostState::Published], true)) {
                throw IllegalPostTransition::direct('scheduled_at');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => PostState::class,
            'scheduled_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PostVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(PostVariant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Posts the scheduler must dispatch: scheduled, with a time that has
     * come. Runs inside a workspace like every other query.
     *
     * @param  Builder<Post>  $query
     */
    #[Scope]
    protected function due(Builder $query, ?DateTimeInterface $now = null): void
    {
        $query->where('state', PostState::Scheduled)
            ->where('scheduled_at', '<=', $now ?? now())
            ->orderBy('scheduled_at')
            ->orderBy('id');
    }

    /**
     * @param  Builder<Post>  $query
     */
    #[Scope]
    protected function inState(Builder $query, PostState ...$states): void
    {
        $query->whereIn('state', $states);
    }

    public function isEditable(): bool
    {
        return $this->state->isEditable();
    }

    /**
     * draft -> approved. The only way into the approved state, and it takes
     * a human: a user who may edit content in this post's workspace.
     *
     * @throws ApprovalNotAllowed
     * @throws IllegalPostTransition
     */
    public function approve(User $approver): static
    {
        if (! $approver->exists || ! ($this->workspace?->roleOf($approver)?->canEditContent() ?? false)) {
            throw ApprovalNotAllowed::for($this, $approver);
        }

        return $this->transition([PostState::Draft], PostState::Approved, [
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);
    }

    /**
     * draft -> rejected.
     *
     * @throws IllegalPostTransition
     */
    public function reject(): static
    {
        return $this->transition([PostState::Draft], PostState::Rejected);
    }

    /**
     * approved, scheduled, failed or rejected -> draft. Erases the approval,
     * so the post must be approved again before it can be published.
     *
     * @throws IllegalPostTransition
     */
    public function returnToDraft(): static
    {
        return $this->transition([PostState::Approved, PostState::Scheduled, PostState::Failed, PostState::Rejected], PostState::Draft, [
            'approved_by' => null,
            'approved_at' => null,
        ]);
    }

    /**
     * approved -> scheduled, at the given time or else at the scheduled_at
     * the post already has. On a post that is already scheduled this only
     * moves the time. The time is stored in UTC.
     *
     * @throws IllegalPostTransition
     */
    public function schedule(?DateTimeInterface $at = null): static
    {
        $at = $at !== null ? CarbonImmutable::instance($at)->utc() : $this->scheduled_at;

        if ($at === null) {
            throw IllegalPostTransition::missingTime($this);
        }

        if ($this->state === PostState::Scheduled) {
            return $this->apply(PostState::Scheduled, PostState::Scheduled, ['scheduled_at' => $at]);
        }

        return $this->transition([PostState::Approved], PostState::Scheduled, ['scheduled_at' => $at]);
    }

    /**
     * scheduled -> approved. Takes the post out of the queue and keeps its
     * approval and its time.
     *
     * @throws IllegalPostTransition
     */
    public function unschedule(): static
    {
        return $this->transition([PostState::Scheduled], PostState::Approved);
    }

    /**
     * scheduled -> publishing, for the scheduler. Returns false, without
     * throwing, when the post is not scheduled or another process claimed it
     * first: exactly one caller ever gets true for one scheduling.
     */
    public function claimForPublishing(): bool
    {
        static::guardWorkspace($this);

        $claimed = static::query()
            ->whereKey($this->getKey())
            ->where('state', PostState::Scheduled)
            ->update(['state' => PostState::Publishing]) === 1;

        $this->refresh();

        return $claimed;
    }

    /**
     * publishing -> published when every variant is on its platform,
     * otherwise publishing -> failed. Call it once no variant will be tried
     * again. Returns the new state.
     *
     * @throws IllegalPostTransition
     */
    public function finishPublishing(): PostState
    {
        $variants = $this->variants()->get();

        $allPublished = $variants->isNotEmpty()
            && $variants->every(fn (PostVariant $variant): bool => $variant->isPublished());

        return $this->transition([PostState::Publishing], $allPublished ? PostState::Published : PostState::Failed)->state;
    }

    /**
     * failed -> scheduled, at the given time or else now. Keeps the approval:
     * content cannot have changed since it was given. Variants that are
     * already published are never sent again.
     *
     * @throws IllegalPostTransition
     */
    public function retry(?DateTimeInterface $at = null): static
    {
        return $this->transition([PostState::Failed], PostState::Scheduled, [
            'scheduled_at' => CarbonImmutable::instance($at ?? now())->utc(),
        ]);
    }

    /**
     * Each transition method names the states it may start from. PostState
     * has the last word on whether the move itself is legal.
     *
     * @param  list<PostState>  $allowedFrom
     * @param  array<string, mixed>  $attributes
     */
    private function transition(array $allowedFrom, PostState $to, array $attributes = []): static
    {
        $from = $this->state;

        if (! in_array($from, $allowedFrom, true) || ! $from->canTransitionTo($to)) {
            throw IllegalPostTransition::between($this, $from, $to);
        }

        return $this->apply($from, $to, $attributes);
    }

    /**
     * Moves the row from one state to another with a single UPDATE that only
     * matches while the row is still in the expected state.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function apply(PostState $from, PostState $to, array $attributes): static
    {
        static::guardWorkspace($this);

        $updated = static::query()
            ->whereKey($this->getKey())
            ->where('state', $from)
            ->update([...$attributes, 'state' => $to]);

        $this->refresh();

        if ($updated !== 1) {
            throw IllegalPostTransition::stale($this, $from, $to);
        }

        return $this;
    }
}
