<?php

namespace App\Domain\Drafting\Generators;

use App\Domain\Drafting\Contracts\DraftGenerator;
use App\Domain\Drafting\DraftingConfig;
use App\Domain\Drafting\DraftingContext;
use App\Domain\Drafting\DraftRequest;
use App\Domain\Drafting\DraftResult;
use App\Domain\Drafting\DraftSchema;
use App\Domain\Drafting\DraftUsage;
use App\Domain\Drafting\Exceptions\DraftGenerationFailed;
use App\Domain\Drafting\Exceptions\DraftingException;
use App\Domain\Drafting\Exceptions\InvalidDraftOutput;
use App\Domain\Drafting\PromptRenderer;
use App\Domain\Drafting\RenderedPrompt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\RawSchema;
use Prism\Prism\Structured\Response;
use SensitiveParameter;
use Throwable;

/**
 * The DraftGenerator for every provider that is reached through Prism. The
 * only class in the application that calls Prism.
 *
 * One call sends the rendered system and user prompt and the draft schema,
 * and nothing else: no tools, no files, no earlier messages. Provider,
 * model, token limit, timeout and key come from the context. The key is
 * handed to Prism for this one call and is never written into config.
 *
 * What the model answers is data. It is validated by DraftResult and stored
 * as text; nothing in it is followed, fetched or run.
 *
 * See docs/platform-notes/llm.md for what Prism does with each call.
 */
class PrismDraftGenerator implements DraftGenerator
{
    private const ATTEMPTS = 2;

    private const TRANSIENT_ERROR_TYPES = ['overloaded_error', 'rate_limit_error', 'api_error', 'timeout_error'];

    public function __construct(private readonly PromptRenderer $prompts) {}

    public function generate(DraftRequest $request, DraftingContext $context): DraftResult
    {
        $prompt = $this->prompts->render($request, $context->promptVersion);
        $usage = null;

        for ($attempt = 1; ; $attempt++) {
            $response = $this->ask($prompt, $context, $usage);
            $usage = $this->usageSoFar($usage, $response, $context);

            $this->failUnlessAnswered($response, $context, $usage);

            try {
                return DraftResult::fromArray($this->storable($response->structured, $context, $usage), $request, $usage);
            } catch (InvalidDraftOutput $invalid) {
                if ($attempt >= self::ATTEMPTS) {
                    throw $this->afterLastAttempt($invalid, $response, $context, $usage);
                }
            }
        }
    }

    private function ask(RenderedPrompt $prompt, DraftingContext $context, ?DraftUsage $usedBefore): Response
    {
        try {
            $pending = Prism::structured()
                ->using($context->provider, $context->model, ['api_key' => $context->key->reveal()])
                ->withSchema(new RawSchema(DraftSchema::NAME, DraftSchema::definition()))
                ->withSystemPrompt($prompt->system)
                ->withPrompt($prompt->user)
                ->withMaxTokens($context->maxTokens)
                ->withClientOptions(['timeout' => $context->timeoutSeconds]);

            $effort = DraftingConfig::effort($context->provider);

            if ($effort !== null) {
                $pending = $pending->withProviderOptions(['effort' => $effort]);
            }

            return $pending->asStructured();
        } catch (Throwable $failure) {
            throw $this->failure($failure, $context, $usedBefore);
        }
    }

    /**
     * The vendor exception is read here and dropped. It is never passed on,
     * because it holds the HTTP request and with it the key.
     */
    private function failure(Throwable $failure, DraftingContext $context, ?DraftUsage $usage): DraftGenerationFailed
    {
        $secrets = [$context->key->reveal()];
        $provider = $context->provider;

        if ($failure instanceof PrismRateLimitedException) {
            return DraftGenerationFailed::retryable(
                $provider,
                'The provider is limiting how fast this API key may be used. The draft can be requested again in a little while.',
                $usage,
                ['status' => 429, 'retry_after' => $failure->retryAfter],
                $secrets,
            );
        }

        if ($failure instanceof PrismProviderOverloadedException) {
            return DraftGenerationFailed::retryable(
                $provider,
                'The provider is overloaded at the moment. The draft can be requested again in a little while.',
                $usage,
                ['status' => 529],
                $secrets,
            );
        }

        if ($failure instanceof PrismRequestTooLargeException) {
            return DraftGenerationFailed::permanent(
                $provider,
                'The request was too large for the provider. Shorten the brief and ask again.',
                $usage,
                ['status' => 413],
                $secrets,
            );
        }

        if ($failure instanceof ConnectionException) {
            return DraftGenerationFailed::retryable(
                $provider,
                "The provider could not be reached or did not answer within {$context->timeoutSeconds} seconds. The draft can be requested again.",
                $usage,
                ['error' => 'connection'],
                $secrets,
            );
        }

        if ($failure instanceof PrismException) {
            return $this->providerError($failure, $context, $usage, $secrets);
        }

        return DraftGenerationFailed::permanent(
            $provider,
            'The draft could not be requested because of an unexpected error. The operator of this instance can find it in the log.',
            $usage,
            [
                'error' => class_basename($failure),
                DraftingException::OPERATOR_DETAIL => class_basename($failure).': '.Str::limit($failure->getMessage(), 300),
            ],
            $secrets,
        );
    }

    /**
     * @param  list<string>  $secrets
     */
    private function providerError(PrismException $failure, DraftingContext $context, ?DraftUsage $usage, #[SensitiveParameter] array $secrets): DraftGenerationFailed
    {
        $status = $failure->httpStatus ?? ($failure->getCode() >= 400 && $failure->getCode() <= 599 ? $failure->getCode() : null);
        $type = preg_match('/\b([a-z]+(?:_[a-z]+)*_error)\b/', $failure->getMessage(), $match) === 1 ? $match[1] : null;
        $said = Str::limit(DraftingException::redact($failure->getMessage(), ...$secrets), 300);
        $details = [
            'status' => $status,
            'error' => $type,
            DraftingException::OPERATOR_DETAIL => "Asked for model [{$context->model}]. The provider said: {$said}",
        ];

        if (($status !== null && $status >= 500) || in_array($type, self::TRANSIENT_ERROR_TYPES, true)) {
            return DraftGenerationFailed::retryable(
                $context->provider,
                'The provider had a problem of its own and did not answer. The draft can be requested again in a little while.',
                $usage,
                $details,
                $secrets,
            );
        }

        $message = match (true) {
            $status === 401 || $type === 'authentication_error' => 'The provider did not accept the API key. An owner of this workspace can check the key under Settings, Drafting.',
            $status === 403 || $type === 'permission_error' => 'The API key is not allowed to do this with the provider. An owner of this workspace can check the key under Settings, Drafting.',
            $status === 404 || $type === 'not_found_error' => 'The provider does not know the model this instance is set up to use. The operator of this instance can correct it.',
            $type === 'billing_error' || str_contains(strtolower($said), 'credit balance') => 'The provider refused the request because the account the API key belongs to has no credit left or has a billing problem.',
            default => 'The provider refused the request. The operator of this instance can find the reason in the log.',
        };

        return DraftGenerationFailed::permanent($context->provider, $message, $usage, $details, $secrets);
    }

    private function usageSoFar(?DraftUsage $before, Response $response, DraftingContext $context): DraftUsage
    {
        $answeredBy = $response->meta->model;

        $attempt = new DraftUsage(
            $context->provider,
            preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/@-]{0,127}$/', $answeredBy) === 1 ? $answeredBy : $context->model,
            max(0, $response->usage->promptTokens),
            max(0, $response->usage->completionTokens),
        );

        return $before?->plus($attempt) ?? $attempt;
    }

    /**
     * An answer that ended for another reason than being finished is not
     * asked for again: the same request would end the same way.
     */
    private function failUnlessAnswered(Response $response, DraftingContext $context, DraftUsage $usage): void
    {
        $secrets = [$context->key->reveal()];
        $details = ['finish_reason' => $response->finishReason->value];

        if (in_array($response->finishReason, [FinishReason::Refusal, FinishReason::ContentFilter], true)) {
            $category = $response->additionalContent['stop_details']['category'] ?? null;

            throw DraftGenerationFailed::permanent(
                $context->provider,
                'The model declined to write this draft. Nothing was created. Change the brief, the pillar or the idea and ask again.',
                $usage,
                [...$details, 'category' => is_string($category) && preg_match('/^[a-z_]{1,40}$/', $category) === 1 ? $category : null],
                $secrets,
            );
        }

        if ($response->finishReason === FinishReason::Length) {
            throw DraftGenerationFailed::permanent(
                $context->provider,
                'The answer was longer than this instance allows and was cut off, so there is no draft. Ask again; if it keeps happening, the operator of this instance can raise the limit.',
                $usage,
                [...$details, DraftingException::OPERATOR_DETAIL => "The answer reached the limit of {$context->maxTokens} tokens, which includes the tokens the model spends on thinking. Raise NOONGUN_DRAFT_MAX_TOKENS, or lower the effort of the provider."],
                $secrets,
            );
        }

        if (in_array($response->finishReason, [FinishReason::ToolCalls, FinishReason::Pause, FinishReason::Error], true)) {
            throw DraftGenerationFailed::permanent(
                $context->provider,
                'The provider ended the answer before the draft was written. Nothing was created. Ask again.',
                $usage,
                $details,
                $secrets,
            );
        }
    }

    /**
     * Text with a NUL character cannot be stored. Such an answer counts as
     * invalid, so it is asked for once more.
     *
     * @param  array<mixed>|null  $structured
     * @return array<mixed>|null
     */
    private function storable(?array $structured, DraftingContext $context, DraftUsage $usage): ?array
    {
        $texts = $structured ?? [];

        array_walk_recursive($texts, function (mixed $value) use ($context, $usage): void {
            if (is_string($value) && str_contains($value, "\0")) {
                throw InvalidDraftOutput::because($context->provider, ['The draft holds a character that cannot be stored.'], $usage);
            }
        });

        return $structured;
    }

    private function afterLastAttempt(InvalidDraftOutput $invalid, Response $response, DraftingContext $context, DraftUsage $usage): DraftingException
    {
        if ($response->structured === null || $response->structured === []) {
            return DraftGenerationFailed::permanent(
                $context->provider,
                "The provider answered {$usage->attempts} times without a draft that could be read. Nothing was created. Ask again; if it keeps happening, tell the operator of this instance.",
                $usage,
                [
                    'finish_reason' => $response->finishReason->value,
                    'error' => 'empty_output',
                    DraftingException::OPERATOR_DETAIL => "Check that the model [{$context->model}] supports structured output.",
                ],
                [$context->key->reveal()],
            );
        }

        return $invalid->withUsage($usage);
    }
}
