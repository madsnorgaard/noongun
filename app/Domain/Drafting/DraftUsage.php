<?php

namespace App\Domain\Drafting;

use InvalidArgumentException;

/**
 * What one generation used, as the provider reported it. $model is the model
 * that answered, which can differ from the one that was asked for. When a
 * generator had to ask twice, the tokens are the sum and $attempts is 2.
 *
 * $inputTokens is all input. $cacheReadTokens and $cacheWriteTokens say how
 * many of those the provider read from or wrote to its prompt cache, which
 * it bills at other prices; they are part of $inputTokens, not added to it.
 */
final readonly class DraftUsage
{
    public function __construct(
        public string $provider,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $attempts = 1,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
    ) {
        if ($inputTokens < 0 || $outputTokens < 0 || $attempts < 1) {
            throw new InvalidArgumentException('Usage needs token counts of zero or more and at least one attempt.');
        }

        if ($cacheReadTokens < 0 || $cacheWriteTokens < 0 || $cacheReadTokens + $cacheWriteTokens > $inputTokens) {
            throw new InvalidArgumentException('Cached tokens are part of the input tokens and cannot be more than those.');
        }
    }

    /**
     * This usage and a later attempt together. Provider and model are those
     * of the later attempt.
     */
    public function plus(self $later): self
    {
        return new self(
            $later->provider,
            $later->model,
            $this->inputTokens + $later->inputTokens,
            $this->outputTokens + $later->outputTokens,
            $this->attempts + $later->attempts,
            $this->cacheReadTokens + $later->cacheReadTokens,
            $this->cacheWriteTokens + $later->cacheWriteTokens,
        );
    }

    /**
     * @return array{provider: string, model: string, input_tokens: int, output_tokens: int, attempts: int}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'attempts' => $this->attempts,
        ];
    }
}
