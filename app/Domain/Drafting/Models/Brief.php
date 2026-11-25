<?php

namespace App\Domain\Drafting\Models;

use App\Domain\Drafting\Casts\AsVoiceRules;
use App\Domain\Drafting\Exceptions\DraftingStateConflict;
use App\Domain\Drafting\VoiceRules;
use App\Domain\Workspaces\Concerns\BelongsToWorkspace;
use App\Domain\Workspaces\Policies\ContentPolicy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\BriefFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * The content brief of a workspace: who they are, what they offer and how
 * they sound. Drafts are generated from it.
 *
 * A brief is versioned. Every save adds a row with the next version number
 * and makes it the current one; earlier rows stay as they were, so a draft
 * run keeps pointing at the text it was generated from. A workspace has at
 * most one current version, which the database enforces. Write through
 * saveNewVersion() (or Actions\SaveBrief from a controller) and read with
 * current().
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $version
 * @property bool $is_current
 * @property string $title
 * @property string $body Markdown
 * @property VoiceRules $voice_rules
 * @property list<array{text: string, url: string|null}> $calls_to_action
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['title', 'body', 'voice_rules', 'calls_to_action'])]
#[UseFactory(BriefFactory::class)]
#[UsePolicy(ContentPolicy::class)]
class Brief extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<BriefFactory> */
    use HasFactory;

    private const CONTENT = ['version', 'title', 'body', 'voice_rules', 'calls_to_action', 'created_by'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'voice_rules' => '{"general":[],"platforms":[]}',
        'calls_to_action' => '[]',
    ];

    protected static function booted(): void
    {
        static::creating(function (Brief $brief): void {
            $brief->setAttribute('version', (int) static::query()->max('version') + 1);
            $brief->setAttribute('is_current', true);
            $brief->setAttribute('calls_to_action', self::cleanCallsToAction($brief->calls_to_action));

            static::query()->where('is_current', true)->update(['is_current' => false]);
        });

        static::updating(function (Brief $brief): void {
            if ($brief->isDirty(self::CONTENT) || $brief->isDirty('is_current')) {
                throw DraftingStateConflict::briefVersionIsFinal($brief);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_current' => 'boolean',
            'voice_rules' => AsVoiceRules::class,
            'calls_to_action' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The brief drafts are generated from, or null when the workspace has
     * not written one yet.
     */
    public static function current(): ?self
    {
        return static::query()->where('is_current', true)->first();
    }

    /**
     * Saves the brief as a new version and makes it the current one. The
     * rows of the workspace are locked first, so two saves at the same time
     * get two version numbers, one after the other.
     *
     * @param  array<array-key, mixed>  $callsToAction  entries of ['text' => ..., 'url' => ...]; entries without a text are dropped
     */
    public static function saveNewVersion(string $title, string $body, VoiceRules $voiceRules, array $callsToAction = [], ?User $author = null): self
    {
        return DB::transaction(function () use ($title, $body, $voiceRules, $callsToAction, $author): self {
            static::query()->lockForUpdate()->pluck('id');

            $brief = new self([
                'title' => $title,
                'body' => $body,
                'voice_rules' => $voiceRules,
                'calls_to_action' => $callsToAction,
            ]);
            $brief->setAttribute('created_by', $author?->id);
            $brief->save();

            return $brief;
        });
    }

    /**
     * @return list<array{text: string, url: string|null}>
     */
    private static function cleanCallsToAction(mixed $calls): array
    {
        $clean = [];

        foreach (is_array($calls) ? $calls : [] as $call) {
            $text = is_array($call) && is_string($call['text'] ?? null) ? trim($call['text']) : '';
            $url = is_array($call) && is_string($call['url'] ?? null) ? trim($call['url']) : '';

            if ($text !== '') {
                $clean[] = ['text' => $text, 'url' => $url === '' ? null : $url];
            }
        }

        return $clean;
    }
}
