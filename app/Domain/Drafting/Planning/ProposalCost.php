<?php

namespace App\Domain\Drafting\Planning;

use App\Domain\Channels\Exceptions\ChannelAdapterNotAvailable;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Idea;
use App\Domain\Content\Models\Pillar;
use App\Domain\Drafting\Actions\BuildDraftRequest;
use App\Domain\Drafting\Actions\ListPlannedAlongside;
use App\Domain\Drafting\CostEstimate;
use App\Domain\Drafting\CostEstimator;
use App\Domain\Drafting\DraftingConfig;
use App\Domain\Drafting\DraftingContext;
use App\Domain\Drafting\DraftingLimits;
use App\Domain\Drafting\DraftRequest;
use App\Domain\Drafting\DraftSchema;
use App\Domain\Drafting\DraftUsage;
use App\Domain\Drafting\ExamplePost;
use App\Domain\Drafting\Exceptions\UnknownPromptVersion;
use App\Domain\Drafting\Models\Brief;
use App\Domain\Drafting\PlannedAlongside;
use App\Domain\Drafting\PromptRenderer;
use App\Domain\Workspaces\CurrentWorkspace;
use DateTimeInterface;

/**
 * What a planned week is expected to cost and what it can cost at most, by
 * the price table, before anything is asked of a provider. It renders the
 * prompt each planned post would be sent and measures it; it sends nothing.
 *
 * Expected: every post drafted at the first try with one answer. The prompt
 * is counted at one token for every three bytes, which is on the high side
 * for running text, and the answer at
 * noongun.drafting.limits.expected_output_tokens.
 *
 * At most: every post uses every attempt, every attempt asks twice (ADR
 * 0011), and every answer runs to the token limit. The prompt is counted at
 * one token for every byte of the prompt and the schema plus an allowance
 * for what the provider wraps around them. A token is never shorter than a
 * byte, so this cannot be exceeded by a longer brief: a longer brief is a
 * longer prompt, and is measured. Input is counted at the dearest input
 * price of the model, which for some providers is writing to their cache.
 *
 * The examples and the list of the other posts of the week are chosen when
 * a post is drafted, not when it is planned, and a post can be approved in
 * between. The expected cost counts them as they are now. The worst case
 * does not rely on that: it counts the most of both a request could ever
 * carry, each marked as one that did well, with every character of an
 * example as four tokens, the most bytes one character has.
 *
 * Both are estimates of the price table, not an invoice. Without a price
 * for the model there is neither.
 */
final class ProposalCost
{
    /** Bytes of prompt counted as one token for the expected cost. */
    private const EXPECTED_BYTES_PER_TOKEN = 3;

    /** The most bytes one character takes, and so the most tokens. */
    private const MOST_BYTES_PER_CHARACTER = 4;

    /** Tokens allowed for what a provider adds around a prompt and a schema. */
    private const FRAMING_TOKENS = 1024;

    public function __construct(
        private readonly BuildDraftRequest $buildRequest,
        private readonly ListPlannedAlongside $listAlongside,
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly PromptRenderer $prompts,
        private readonly CostEstimator $costs,
    ) {}

    /**
     * @return array{expected: CostEstimate, worst: CostEstimate}|null null when the plan is empty, the model has no price or the prompt cannot be rendered
     */
    public function of(WeekPlan $plan, Brief $brief, DraftingContext $context): ?array
    {
        $posts = [];

        foreach ($plan->posts as $position => $post) {
            $others = $plan->posts;
            unset($others[$position]);

            $posts[] = [
                'channels' => $post->channels,
                'pillar' => $post->pillar,
                'idea' => $post->idea,
                'at' => $post->at,
                'alongside' => array_values(array_map(
                    fn (PlannedPost $other): array => ['pillar' => $other->pillar, 'idea' => $other->idea],
                    $others,
                )),
            ];
        }

        return $this->ofPosts($posts, $brief, $context);
    }

    /**
     * The same two figures for one post on the given channels, as "Draft a
     * post" on an idea asks for it.
     *
     * @param  list<Channel>  $channels
     * @param  DateTimeInterface|null  $plannedAt  the planned time, when one was chosen
     * @return array{expected: CostEstimate, worst: CostEstimate}|null null without a channel, without a price for the model or when the prompt cannot be rendered
     */
    public function ofOnePost(array $channels, ?Pillar $pillar, ?Idea $idea, Brief $brief, DraftingContext $context, ?DateTimeInterface $plannedAt = null): ?array
    {
        return $channels === []
            ? null
            : $this->ofPosts([['channels' => $channels, 'pillar' => $pillar, 'idea' => $idea, 'at' => $plannedAt, 'alongside' => []]], $brief, $context);
    }

    /**
     * @param  list<array{channels: list<Channel>, pillar: Pillar|null, idea: Idea|null, at: DateTimeInterface|null, alongside: list<array{pillar: Pillar|null, idea: Idea|null}>}>  $posts
     * @return array{expected: CostEstimate, worst: CostEstimate}|null
     */
    private function ofPosts(array $posts, Brief $brief, DraftingContext $context): ?array
    {
        if ($posts === []) {
            return null;
        }

        $schemaBytes = strlen((string) json_encode(DraftSchema::definition()));
        $workspace = $this->currentWorkspace->getOrFail();
        $expectedInput = 0;
        $worstInput = 0;

        try {
            foreach ($posts as $post) {
                $request = $this->buildRequest->handle(
                    $brief,
                    $post['channels'],
                    $post['pillar'],
                    $post['idea'],
                    alsoPlanned: $this->listAlongside->handle($workspace, $post['at'], $post['alongside']),
                );

                $expectedInput += intdiv($this->bytesOf($request, $context) + $schemaBytes, self::EXPECTED_BYTES_PER_TOKEN) + 1;
                $worstInput += $this->bytesOf(self::carryingTheMost($request), $context)
                    + self::MOST_BYTES_PER_CHARACTER * self::exampleCharacters()
                    + $schemaBytes
                    + self::FRAMING_TOKENS;
            }
        } catch (ChannelAdapterNotAvailable|UnknownPromptVersion) {
            return null;
        }

        $posts = count($posts);
        $answers = DraftingLimits::maxAttempts() * DraftingLimits::ANSWERS_PER_RUN;

        $expected = $this->costs->estimate(new DraftUsage(
            $context->provider,
            $context->model,
            $expectedInput,
            $posts * min(DraftingLimits::expectedOutputTokens(), $context->maxTokens),
        ));

        $worst = $this->costs->atMost(new DraftUsage(
            $context->provider,
            $context->model,
            $worstInput * $answers,
            $posts * $answers * $context->maxTokens,
            $answers,
        ));

        return $expected !== null && $worst !== null ? ['expected' => $expected, 'worst' => $worst] : null;
    }

    /**
     * @throws UnknownPromptVersion
     */
    private function bytesOf(DraftRequest $request, DraftingContext $context): int
    {
        $prompt = $this->prompts->render($request, $context->promptVersion);

        return strlen($prompt->system) + strlen($prompt->user);
    }

    /**
     * The request with as many examples as it could carry, each with the
     * longest pillar name, the mark of a post that did well and no text,
     * and with the longest list of posts
     * planned alongside. Rendered, it is everything around the texts of
     * the examples at its largest. The texts themselves are counted apart,
     * at their limit.
     */
    private static function carryingTheMost(DraftRequest $request): DraftRequest
    {
        $widest = str_repeat("\u{1F600}", max(ExamplePost::PILLAR_LENGTH, PlannedAlongside::TEXT_LENGTH));
        $examples = [];

        if (self::exampleCharacters() > 0) {
            foreach ($request->channels as $channel) {
                for ($i = 0; $i < DraftingConfig::examplesPerChannel(); $i++) {
                    $examples[] = new ExamplePost($channel->platform, '', $channel->channelId, $widest, performedWell: true);
                }
            }
        }

        return $request->carrying(
            $examples,
            array_fill(0, PlannedAlongside::MAX_ENTRIES, new PlannedAlongside($widest, $widest, $widest)),
        );
    }

    /**
     * The most characters the examples of one request hold, or none when
     * examples are turned off.
     */
    private static function exampleCharacters(): int
    {
        return DraftingConfig::examplesPerChannel() > 0 ? DraftingConfig::exampleCharacters() : 0;
    }
}
