<?php

namespace App\Domain\Drafting\Models;

use App\Domain\Content\Models\Idea;
use App\Domain\Content\Models\Pillar;
use App\Domain\Content\Models\Post;
use App\Domain\Drafting\CostEstimate;
use App\Domain\Drafting\CredentialScope;
use App\Domain\Drafting\DraftingContext;
use App\Domain\Drafting\DraftResult;
use App\Domain\Drafting\DraftRunStatus;
use App\Domain\Drafting\DraftUsage;
use App\Domain\Drafting\Exceptions\DraftingException;
use App\Domain\Drafting\Exceptions\DraftingStateConflict;
use App\Domain\Workspaces\Concerns\BelongsToWorkspace;
use App\Domain\Workspaces\Policies\ContentPolicy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\DraftRunFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * One generation: one request to a provider for one post, with who asked,
 * what was asked, which provider, model and prompt version answered, what
 * it used and what it is estimated to have cost. The basis for cost
 * tracking now and for metering later.
 *
 * Nothing is fillable. A run is opened with start() and moves only through
 * markRunning(), markSucceeded() and markFailed(), each of which is one
 * conditional UPDATE, so two processes can never both win the same move:
 *
 *     pending -> running -> succeeded
 *     pending or running -> failed
 *
 * provider, model and prompt_version are what was asked for when the run
 * was opened. A run never holds the prompt or the key.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $requested_by
 * @property DraftRunStatus $status
 * @property string $provider
 * @property string $model
 * @property string $prompt_version
 * @property CredentialScope $credential_scope
 * @property int|null $brief_id
 * @property int|null $pillar_id
 * @property int|null $idea_id
 * @property array<string, mixed> $request
 * @property int $attempts
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property string|null $cost_estimate decimal with six places
 * @property string|null $cost_currency
 * @property string|null $rationale
 * @property string|null $alt_text
 * @property bool $link_dropped the model suggested a link the author never gave, and it was left out
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Post|null $post
 */
#[UseFactory(DraftRunFactory::class)]
#[UsePolicy(ContentPolicy::class)]
class DraftRun extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<DraftRunFactory> */
    use HasFactory;

    public const MAX_FAILURE_REASON_LENGTH = 1000;

    /** request['source'] of a run for one post that was asked for on an idea, outside a proposed week. */
    public const SOURCE_IDEA = 'idea';

    /** What a failed run of that kind says in place of "propose the week again". */
    public const DRAFT_AGAIN = 'draft the post again from the Ideas page';

    /** Attributes that only the mark methods may change. */
    private const GUARDED_BY_TRANSITIONS = [
        'status', 'attempts', 'input_tokens', 'output_tokens', 'cost_estimate', 'cost_currency',
        'rationale', 'alt_text', 'link_dropped', 'failure_reason', 'started_at', 'finished_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => DraftRunStatus::Pending->value,
        'request' => '{}',
        'attempts' => 0,
    ];

    protected static function booted(): void
    {
        static::updating(function (DraftRun $run): void {
            foreach (self::GUARDED_BY_TRANSITIONS as $attribute) {
                if ($run->isDirty($attribute)) {
                    throw DraftingStateConflict::writtenDirectly($run, $attribute);
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DraftRunStatus::class,
            'credential_scope' => CredentialScope::class,
            'request' => 'array',
            'attempts' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'link_dropped' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<Brief, $this>
     */
    public function brief(): BelongsTo
    {
        return $this->belongsTo(Brief::class);
    }

    /**
     * @return BelongsTo<Pillar, $this>
     */
    public function pillar(): BelongsTo
    {
        return $this->belongsTo(Pillar::class);
    }

    /**
     * @return BelongsTo<Idea, $this>
     */
    public function idea(): BelongsTo
    {
        return $this->belongsTo(Idea::class);
    }

    /**
     * The draft this run produced, if it succeeded and the post still exists.
     *
     * @return HasOne<Post, $this>
     */
    public function post(): HasOne
    {
        return $this->hasOne(Post::class);
    }

    /**
     * @param  Builder<DraftRun>  $query
     */
    #[Scope]
    protected function inStatus(Builder $query, DraftRunStatus ...$statuses): void
    {
        $query->whereIn('status', $statuses);
    }

    /**
     * Opens a run in the pending state for the current workspace. $request
     * is a free-form note of what was asked that the columns do not hold,
     * for example ['channel_ids' => [1, 2], 'source_url' => ..., 'planned_at' => ...].
     * It is shown to the user; never put a prompt or a key in it.
     *
     * @param  array<string, mixed>  $request
     */
    public static function start(
        User $requestedBy,
        DraftingContext $context,
        array $request = [],
        ?Brief $brief = null,
        ?Pillar $pillar = null,
        ?Idea $idea = null,
    ): self {
        $run = new self;

        $run->forceFill([
            'requested_by' => $requestedBy->id,
            'provider' => $context->provider,
            'model' => $context->model,
            'prompt_version' => $context->promptVersion,
            'credential_scope' => $context->key->scope,
            'brief_id' => $brief?->id,
            'pillar_id' => $pillar?->id,
            'idea_id' => $idea?->id,
            'request' => $request,
        ])->save();

        return $run;
    }

    /**
     * Notes on a pending run that it is drafted for fewer channels than it
     * was planned for, because the others can no longer publish. The run
     * then names only the channels it writes for and only their slots, so
     * that the slot of a channel that was left out counts as open when the
     * week is proposed again. Returns false when the run is no longer
     * pending.
     *
     * @param  list<int>  $channelIds  the channels that are drafted for
     * @param  list<int>  $slotIds  the slots of those channels
     * @param  list<int>  $leftOutChannelIds
     */
    public function draftForFewer(array $channelIds, array $slotIds, array $leftOutChannelIds): bool
    {
        return $this->move([DraftRunStatus::Pending], [
            'request' => json_encode([
                ...$this->request,
                'channel_ids' => $channelIds,
                'slot_ids' => $slotIds,
                'left_out_channel_ids' => $leftOutChannelIds,
            ]),
        ]);
    }

    /**
     * Whether the run drafts one post that was asked for on an idea, and
     * not a post of a proposed week.
     */
    public function isFromIdea(): bool
    {
        return ($this->request['source'] ?? null) === self::SOURCE_IDEA;
    }

    /**
     * The reasons this application gives for a failed run end with what to
     * do next, and that was written for a proposed week. A run for one post
     * from an idea has no week to propose again, so its reason names the
     * Ideas page. Every failure is stored through markFailed(), which is
     * why this is the one place.
     */
    private function inItsOwnWords(string $reason): string
    {
        if (! $this->isFromIdea()) {
            return $reason;
        }

        return (string) preg_replace_callback(
            '/\b(p)ropose the week again(?: to [^.]*)?\./i',
            fn (array $found): string => ($found[1] === 'P' ? ucfirst(self::DRAFT_AGAIN) : self::DRAFT_AGAIN).'.',
            $reason,
        );
    }

    /**
     * pending -> running. Returns whether this call started the run: a job
     * that gets false was queued twice or arrives after the run was
     * settled, and must do nothing.
     */
    public function markRunning(): bool
    {
        return $this->move([DraftRunStatus::Pending], [
            'status' => DraftRunStatus::Running,
            'started_at' => now(),
        ]);
    }

    /**
     * running -> succeeded, with what the result used and, when a price is
     * known, what that is estimated to have cost. Returns whether this call
     * recorded it. Call it through Actions\CreateDraftFromResult, which
     * creates the post in the same transaction.
     */
    public function markSucceeded(DraftResult $result, ?CostEstimate $cost = null): bool
    {
        return $this->move([DraftRunStatus::Running], [
            'status' => DraftRunStatus::Succeeded,
            ...self::usageColumns($result->usage, $cost),
            'rationale' => $result->rationale,
            'alt_text' => $result->altText,
            'link_dropped' => $result->linkDropped,
            'finished_at' => now(),
        ]);
    }

    /**
     * pending or running -> failed, with a reason a user can read. The
     * reason is cleared of credentials and cut to a sensible length. Pass
     * the usage when the provider reported any, so a failed run still shows
     * what it cost. Returns whether this call recorded it.
     */
    public function markFailed(string $reason, ?DraftUsage $usage = null, ?CostEstimate $cost = null): bool
    {
        $reason = Str::limit(trim(DraftingException::redact($this->inItsOwnWords($reason))), self::MAX_FAILURE_REASON_LENGTH);

        return $this->move([DraftRunStatus::Pending, DraftRunStatus::Running], [
            'status' => DraftRunStatus::Failed,
            ...($usage !== null ? self::usageColumns($usage, $cost) : []),
            'failure_reason' => $reason === '' ? 'The run failed.' : $reason,
            'finished_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function usageColumns(DraftUsage $usage, ?CostEstimate $cost): array
    {
        return [
            'attempts' => $usage->attempts,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cost_estimate' => $cost?->amount,
            'cost_currency' => $cost?->currency,
        ];
    }

    /**
     * One UPDATE that only matches while the row is in one of the expected
     * states.
     *
     * @param  list<DraftRunStatus>  $from
     * @param  array<string, mixed>  $attributes
     */
    private function move(array $from, array $attributes): bool
    {
        static::guardWorkspace($this);

        $moved = static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', $from)
            ->update($attributes) === 1;

        $this->refresh();

        return $moved;
    }
}
