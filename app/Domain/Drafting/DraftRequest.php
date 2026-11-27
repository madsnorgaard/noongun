<?php

namespace App\Domain\Drafting;

use InvalidArgumentException;

/**
 * What one draft is generated from: one post, one text per target channel.
 *
 * It holds plain values, never models, so that everything a provider can be
 * sent is visible here: the brief, the voice rules, the pillar, the idea or
 * a source URL, the target channels with their limits, texts the workspace
 * approved earlier as examples, and a few words about the other posts of
 * the same week. No tokens, no user data, nothing of another workspace. Build it with
 * Actions\BuildDraftRequest; PromptRenderer turns it into the prompt.
 */
final readonly class DraftRequest
{
    /** @var non-empty-list<TargetChannel> */
    public array $channels;

    /** @var list<array{text: string, url: string|null}> */
    public array $callsToAction;

    /** @var list<ExamplePost> */
    public array $examples;

    /** @var list<PlannedAlongside> */
    public array $alsoPlanned;

    /**
     * @param  array<array-key, TargetChannel>  $channels  at least one, each channel once
     * @param  array<array-key, array{text: string, url?: string|null}>  $callsToAction
     * @param  array<array-key, ExamplePost>  $examples  texts the workspace approved earlier
     * @param  array<array-key, PlannedAlongside>  $alsoPlanned  the other posts of the same week
     */
    public function __construct(
        public string $briefTitle,
        public string $briefBody,
        public VoiceRules $voiceRules,
        array $channels,
        array $callsToAction = [],
        public ?string $pillarName = null,
        public ?string $pillarDescription = null,
        public ?string $ideaTitle = null,
        public ?string $ideaNotes = null,
        public ?string $sourceUrl = null,
        array $examples = [],
        array $alsoPlanned = [],
    ) {
        $channels = array_values($channels);
        $ids = array_map(fn (TargetChannel $channel): int => $channel->channelId, $channels);

        if ($channels === [] || count(array_unique($ids)) !== count($ids)) {
            throw new InvalidArgumentException('A draft request needs at least one target channel, and each channel once.');
        }

        $this->channels = $channels;
        $this->callsToAction = array_values(array_map(
            fn (array $call): array => ['text' => $call['text'], 'url' => $call['url'] ?? null],
            $callsToAction,
        ));
        $this->examples = array_values($examples);
        $this->alsoPlanned = array_values($alsoPlanned);
    }

    /**
     * The same request with other examples and another list of the posts
     * planned alongside it. Planning\ProposalCost measures a request this
     * way with the most of both it could carry.
     *
     * @param  array<array-key, ExamplePost>  $examples
     * @param  array<array-key, PlannedAlongside>  $alsoPlanned
     */
    public function carrying(array $examples, array $alsoPlanned): self
    {
        return new self(
            $this->briefTitle,
            $this->briefBody,
            $this->voiceRules,
            $this->channels,
            $this->callsToAction,
            $this->pillarName,
            $this->pillarDescription,
            $this->ideaTitle,
            $this->ideaNotes,
            $this->sourceUrl,
            $examples,
            $alsoPlanned,
        );
    }

    /**
     * @return non-empty-list<int>
     */
    public function channelIds(): array
    {
        return array_map(fn (TargetChannel $channel): int => $channel->channelId, $this->channels);
    }

    public function channel(int $channelId): ?TargetChannel
    {
        foreach ($this->channels as $channel) {
            if ($channel->channelId === $channelId) {
                return $channel;
            }
        }

        return null;
    }

    /**
     * The whole request as data. This is exactly what the prompt templates
     * receive, and nothing else reaches them.
     *
     * @return array{
     *     brief: array{title: string, body: string, calls_to_action: list<array{text: string, url: string|null}>},
     *     voice_rules: array{general: list<string>, platforms: array<string, list<string>>},
     *     pillar: array{name: string, description: string|null}|null,
     *     idea: array{title: string, notes: string|null}|null,
     *     source_url: string|null,
     *     channels: non-empty-list<array{channel_id: int, platform: string, max_body_length: int, link_previews: bool, media_types: list<string>, max_media_items: int, link_title_length: int|null, link_description_length: int|null, voice_rules: list<string>}>,
     *     examples: list<array{platform: string, body: string, channel_id: int|null, pillar: string|null, performed_well: bool}>,
     *     also_planned: list<array{pillar: string|null, idea: string|null, opening: string|null}>
     * }
     */
    public function toArray(): array
    {
        return [
            'brief' => [
                'title' => $this->briefTitle,
                'body' => $this->briefBody,
                'calls_to_action' => $this->callsToAction,
            ],
            'voice_rules' => $this->voiceRules->toArray(),
            'pillar' => $this->pillarName === null ? null : [
                'name' => $this->pillarName,
                'description' => $this->pillarDescription,
            ],
            'idea' => $this->ideaTitle === null ? null : [
                'title' => $this->ideaTitle,
                'notes' => $this->ideaNotes,
            ],
            'source_url' => $this->sourceUrl,
            'channels' => array_map(
                fn (TargetChannel $channel): array => [
                    ...$channel->toArray(),
                    'voice_rules' => $this->voiceRules->platforms[$channel->platform] ?? [],
                ],
                $this->channels,
            ),
            'examples' => array_map(fn (ExamplePost $example): array => $example->toArray(), $this->examples),
            'also_planned' => array_map(fn (PlannedAlongside $post): array => $post->toArray(), $this->alsoPlanned),
        ];
    }
}
