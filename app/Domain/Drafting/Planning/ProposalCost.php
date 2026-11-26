<?php

namespace App\Domain\Drafting\Planning;

use App\Domain\Channels\Exceptions\ChannelAdapterNotAvailable;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Idea;
use App\Domain\Content\Models\Pillar;
use App\Domain\Drafting\Actions\BuildDraftRequest;
use App\Domain\Drafting\CostEstimate;
use App\Domain\Drafting\CostEstimator;
use App\Domain\Drafting\DraftingContext;
use App\Domain\Drafting\DraftingLimits;
use App\Domain\Drafting\DraftSchema;
use App\Domain\Drafting\DraftUsage;
use App\Domain\Drafting\Exceptions\UnknownPromptVersion;
use App\Domain\Drafting\Models\Brief;
use App\Domain\Drafting\PromptRenderer;

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
 * Both are estimates of the price table, not an invoice. Without a price
 * for the model there is neither.
 */
final class ProposalCost
{
    /** Bytes of prompt counted as one token for the expected cost. */
    private const EXPECTED_BYTES_PER_TOKEN = 3;

    /** Tokens allowed for what a provider adds around a prompt and a schema. */
    private const FRAMING_TOKENS = 1024;

    public function __construct(
        private readonly BuildDraftRequest $buildRequest,
        private readonly PromptRenderer $prompts,
        private readonly CostEstimator $costs,
    ) {}

    /**
     * @return array{expected: CostEstimate, worst: CostEstimate}|null null when the plan is empty, the model has no price or the prompt cannot be rendered
     */
    public function of(WeekPlan $plan, Brief $brief, DraftingContext $context): ?array
    {
        return $this->ofPosts(array_map(
            fn (PlannedPost $post): array => ['channels' => $post->channels, 'pillar' => $post->pillar, 'idea' => $post->idea],
            $plan->posts,
        ), $brief, $context);
    }

    /**
     * The same two figures for one post on the given channels, as "Draft a
     * post" on an idea asks for it.
     *
     * @param  list<Channel>  $channels
     * @return array{expected: CostEstimate, worst: CostEstimate}|null null without a channel, without a price for the model or when the prompt cannot be rendered
     */
    public function ofOnePost(array $channels, ?Pillar $pillar, ?Idea $idea, Brief $brief, DraftingContext $context): ?array
    {
        return $channels === []
            ? null
            : $this->ofPosts([['channels' => $channels, 'pillar' => $pillar, 'idea' => $idea]], $brief, $context);
    }

    /**
     * @param  list<array{channels: list<Channel>, pillar: Pillar|null, idea: Idea|null}>  $posts
     * @return array{expected: CostEstimate, worst: CostEstimate}|null
     */
    private function ofPosts(array $posts, Brief $brief, DraftingContext $context): ?array
    {
        if ($posts === []) {
            return null;
        }

        $schemaBytes = strlen((string) json_encode(DraftSchema::definition()));
        $expectedInput = 0;
        $worstInput = 0;

        try {
            foreach ($posts as $post) {
                $prompt = $this->prompts->render(
                    $this->buildRequest->handle($brief, $post['channels'], $post['pillar'], $post['idea']),
                    $context->promptVersion,
                );

                $bytes = strlen($prompt->system) + strlen($prompt->user) + $schemaBytes;

                $expectedInput += intdiv($bytes, self::EXPECTED_BYTES_PER_TOKEN) + 1;
                $worstInput += $bytes + self::FRAMING_TOKENS;
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
}
