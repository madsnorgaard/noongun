<?php

namespace Tests\Fakes;

use App\Domain\Drafting\Contracts\DraftGenerator;
use App\Domain\Drafting\DraftingContext;
use App\Domain\Drafting\DraftRequest;
use App\Domain\Drafting\DraftResult;
use App\Domain\Drafting\DraftUsage;
use App\Domain\Drafting\Exceptions\DraftGenerationFailed;
use App\Domain\Drafting\Exceptions\DraftingException;
use App\Domain\Drafting\Exceptions\InvalidDraftOutput;
use Closure;
use PHPUnit\Framework\Assert;

/**
 * A DraftGenerator that calls no provider. It records every call, answers
 * with a valid draft for whatever was asked, and can be told to answer
 * differently or to fail.
 *
 *     $fake = fakeDraftGenerator();                  // registered for the default provider
 *     $fake->failRetryable();                        // the next call throws
 *     $fake->returnsOutput(['variants' => []]);      // raw output, validated like a real answer
 *     ...
 *     $fake->assertGeneratedTimes(1);
 *
 * Register it with fakeDraftGenerator() from tests/Support/drafting.php. It
 * builds its results with DraftResult::fromArray(), so what it returns has
 * passed the same validation as a real answer.
 */
class FakeDraftGenerator implements DraftGenerator
{
    public const INPUT_TOKENS = 1200;

    public const OUTPUT_TOKENS = 300;

    /** @var list<array{request: DraftRequest, context: DraftingContext}> */
    public array $calls = [];

    /** @var list<DraftingException> */
    private array $failures = [];

    /** @var (Closure(DraftRequest, DraftingContext, int): array<array-key, mixed>)|null */
    private ?Closure $outputUsing = null;

    public function generate(DraftRequest $request, DraftingContext $context): DraftResult
    {
        $this->calls[] = ['request' => $request, 'context' => $context];

        if ($this->failures !== []) {
            throw array_shift($this->failures);
        }

        $number = count($this->calls);

        $output = $this->outputUsing !== null
            ? ($this->outputUsing)($request, $context, $number)
            : self::outputFor($request, $number);

        return DraftResult::fromArray($output, $request, self::usage($context));
    }

    /**
     * The output a provider would give for the request: one text for each
     * channel, no link.
     *
     * @return array{variants: list<array{channel_id: int, body: string}>, link: string|null, alt_text: string|null, rationale: string}
     */
    public static function outputFor(DraftRequest $request, int $number = 1): array
    {
        $variants = [];

        foreach ($request->channels as $channel) {
            $variants[] = [
                'channel_id' => $channel->channelId,
                'body' => "Generated draft {$number} for {$channel->platform}.",
            ];
        }

        return [
            'variants' => $variants,
            'link' => $request->sourceUrl,
            'alt_text' => null,
            'rationale' => "Generated draft {$number} fits the brief.",
        ];
    }

    public static function usage(DraftingContext $context, int $attempts = 1): DraftUsage
    {
        return new DraftUsage($context->provider, $context->model, self::INPUT_TOKENS * $attempts, self::OUTPUT_TOKENS * $attempts, $attempts);
    }

    // Configuration

    /**
     * Decide the raw output. The closure gets the request, the context and
     * the number of this call, starting at 1. The output is validated: an
     * invalid one makes generate() throw InvalidDraftOutput, as it should.
     *
     * @param  array<array-key, mixed>|Closure(DraftRequest, DraftingContext, int): array<array-key, mixed>  $output
     */
    public function returnsOutput(array|Closure $output): static
    {
        $this->outputUsing = $output instanceof Closure ? $output : fn (): array => $output;

        return $this;
    }

    /**
     * The next $times calls throw a failure worth trying again later.
     */
    public function failRetryable(int $times = 1, string $message = 'The provider is busy.'): static
    {
        return $this->failNext(DraftGenerationFailed::retryable('fake', $message), $times);
    }

    /**
     * The next $times calls throw a failure that will repeat.
     */
    public function failPermanently(int $times = 1, string $message = 'The provider refused the request.'): static
    {
        return $this->failNext(DraftGenerationFailed::permanent('fake', $message), $times);
    }

    /**
     * The next $times calls throw what a generator throws after two invalid
     * answers, with the usage of both.
     */
    public function failInvalid(int $times = 1): static
    {
        return $this->failNext(InvalidDraftOutput::because(
            'fake',
            ['There is no text for channel 1.'],
            new DraftUsage('fake', 'fake-model', self::INPUT_TOKENS * 2, self::OUTPUT_TOKENS * 2, 2),
        ), $times);
    }

    public function failNext(DraftingException $failure, int $times = 1): static
    {
        for ($i = 0; $i < $times; $i++) {
            $this->failures[] = $failure;
        }

        return $this;
    }

    // Inspection

    public function generateCount(): int
    {
        return count($this->calls);
    }

    public function lastRequest(): ?DraftRequest
    {
        return $this->calls === [] ? null : $this->calls[array_key_last($this->calls)]['request'];
    }

    public function lastContext(): ?DraftingContext
    {
        return $this->calls === [] ? null : $this->calls[array_key_last($this->calls)]['context'];
    }

    public function assertGeneratedTimes(int $times): static
    {
        Assert::assertSame($times, $this->generateCount(), "Expected {$times} generate call(s), got {$this->generateCount()}.");

        return $this;
    }

    public function assertNothingGenerated(): static
    {
        return $this->assertGeneratedTimes(0);
    }
}
