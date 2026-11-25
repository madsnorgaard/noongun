<?php

namespace App\Http\Presenters;

use App\Domain\Channels\ChannelManager;
use App\Domain\Drafting\Models\Brief;
use App\Domain\Drafting\VoiceRules;
use Illuminate\Support\Str;

/**
 * What the brief pages show of a brief version. The text of a brief goes out
 * as it was typed; the pages show it as text and never as markup.
 */
final class BriefPresenter
{
    /**
     * Voice rules offered to a workspace that has none yet. They are a
     * suggestion the user takes with a click, and are never saved for them.
     *
     * @var array{general: list<string>, platforms: array<string, list<string>>}
     */
    public const STARTER_VOICE = [
        'general' => [
            'No contractions.',
            'Short hyphens instead of em dashes.',
        ],
        'platforms' => [
            'facebook' => ['Personal and conversational, like old school Facebook. No hashtag walls.'],
            'linkedin' => ['Professional but human.'],
        ],
    ];

    private const LABELS = ['linkedin' => 'LinkedIn'];

    public function __construct(private readonly ChannelManager $channels) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Brief $brief): array
    {
        return [
            ...$this->summary($brief),
            'title' => $brief->title,
            'body' => $brief->body,
            'voice_rules' => $this->voiceRules($brief->voice_rules),
            'calls_to_action' => $brief->calls_to_action,
        ];
    }

    /**
     * One line of the version list.
     *
     * @return array{version: int, is_current: bool, saved_at: string|null, saved_by: string|null}
     */
    public function summary(Brief $brief): array
    {
        return [
            'version' => $brief->version,
            'is_current' => $brief->is_current,
            'saved_at' => $brief->created_at?->toIso8601String(),
            'saved_by' => $brief->author?->name,
        ];
    }

    /**
     * @return array{general: list<string>, platforms: object}
     */
    public function voiceRules(VoiceRules $rules): array
    {
        return ['general' => $rules->general, 'platforms' => (object) $rules->platforms];
    }

    /**
     * @return array{general: list<string>, platforms: object}
     */
    public function starterVoice(): array
    {
        return $this->voiceRules(VoiceRules::fromArray(self::STARTER_VOICE));
    }

    /**
     * The platforms that get a list of voice rules of their own: every
     * platform this instance knows, the ones the starter rules name, and
     * whatever the given brief already has rules for.
     *
     * @return list<string>
     */
    public function platformNames(?Brief $brief): array
    {
        return array_values(array_unique([
            ...$this->channels->platforms(),
            ...array_keys(self::STARTER_VOICE['platforms']),
            ...array_keys($brief->voice_rules->platforms ?? []),
        ]));
    }

    /**
     * @return list<array{name: string, label: string}>
     */
    public function platforms(?Brief $brief): array
    {
        return array_map(
            fn (string $name): array => ['name' => $name, 'label' => $this->platformLabel($name)],
            $this->platformNames($brief),
        );
    }

    public function platformLabel(string $platform): string
    {
        return self::LABELS[$platform] ?? Str::headline($platform);
    }
}
