# LLM layer - Prism and Anthropic

Findings for M4 (issue #4). Dated entries; newest last. Sources are the Prism
package as installed (its `docs/` folder and source under
`vendor/prism-php/prism`), Packagist, and Anthropic's API documentation on
platform.claude.com.

## 2026-11-25 - review before fixing the drafting contract

### 1. Package and version

`prism-php/prism` **v0.103.0**, released 2026-11-07 (Packagist). It requires PHP
`^8.2` and `laravel/framework` `^12.61.1|^13.12.0`, so it installs on our Laravel
13 without conflicts. `composer.json` holds `^0.103`. Prism is still below 1.0,
and for a 0.x version a caret range allows patch releases only (0.103.x), which
is what we want: its minor releases have changed behaviour before.

Prism registers itself through package discovery and reads `config/prism.php`
from the package when the application has none. We do not publish that file.
Everything Noon Gun needs is in `config/noongun.php` under `drafting` and is
passed to Prism per call.

### 2. The call for structured output

```php
$response = Prism::structured()
    ->using($provider, $model, ['api_key' => $key])   // third argument overrides the provider config for this call
    ->withSchema(new RawSchema(DraftSchema::NAME, DraftSchema::definition()))
    ->withSystemPrompt($prompt->system)
    ->withPrompt($prompt->user)
    ->withMaxTokens($maxTokens)
    ->withClientOptions(['timeout' => $seconds])
    ->asStructured();

$response->structured;                 // ?array, the decoded JSON
$response->finishReason;               // Prism\Prism\Enums\FinishReason
$response->usage->promptTokens;        // Anthropic usage.input_tokens
$response->usage->completionTokens;    // Anthropic usage.output_tokens
$response->meta->model;                // the model that answered
```

- **Per-call key.** `using()` takes a provider config array as its third argument,
  and `PrismManager::resolve()` merges it over the configured values. This is how
  a workspace key is used without touching global config. Source:
  `src/Concerns/ConfiguresProviders.php`, `src/PrismManager.php`.
- **Schema from an array.** `Prism\Prism\Schema\RawSchema` wraps a plain JSON
  schema array, so one definition (`App\Domain\Drafting\DraftSchema`) serves both
  the provider and our validator. Source: `src/Schema/RawSchema.php`.
- **Timeout.** Prism's default request timeout is 30 seconds
  (`prism.request_timeout`). A draft on a thinking model can take longer, so the
  generator sets it per call from `noongun.drafting.timeout`.
- **Token limit.** Without `withMaxTokens()` Prism sends `max_tokens: 64000` to
  Anthropic. We always send our own from `noongun.drafting.max_tokens`.

### 3. How Anthropic structured output works through Prism

Prism uses Anthropic's **native structured outputs** by default: it sends
`output_config.format = {type: "json_schema", schema: ...}` and the model's
answer is constrained to the schema. Source:
`src/Providers/Anthropic/Handlers/StructuredStrategies/NativeOutputFormatStructuredStrategy.php`,
`docs/core-concepts/structured-output.md`.

The other mode, `->withProviderOptions(['use_tool_calling' => true])`, forces a
tool call. Prism's documentation warns that Claude Opus 5.5, Sonnet 5.5 and
Fable 5.1 reject a forced tool choice with a 400. **Do not use tool calling mode.**

Limits of the native mode (Anthropic documentation, "JSON schema limitations"),
which `DraftSchema` is written to stay inside:

- every object needs `additionalProperties: false`;
- supported: object, array, string, integer, number, boolean, null, `enum`,
  `const`, `anyOf`, `allOf`, `$ref`;
- not supported: `minLength`, `maxLength`, `minimum`, `maximum`, `multipleOf`,
  complex array constraints, recursive schemas.

So the schema cannot say "at most 3000 characters" or "exactly these channel
ids". Those are checked after the answer, in `DraftResult::fromArray()`, and the
length limit is stated in the prompt.

Structured output does not mean the answer is always usable:

- **Invalid JSON does not throw.** The native strategy does
  `json_decode($text, true) ?? []`. A truncated or broken answer arrives as an
  empty `structured` array. `DraftResult::fromArray()` rejects it.
- **A refusal does not throw.** It arrives as `FinishReason::Refusal` with an
  empty `structured`. The generator must check `finishReason` and report a
  refusal as `DraftGenerationFailed`, not as invalid output: asking again does
  not help.
- **`FinishReason::Length`** means `max_tokens` was reached and the JSON is cut
  off. On the current models thinking is always on and its tokens count against
  `max_tokens`, so the limit must leave room for it. The default is 16000.

### 4. How usage is returned

`$response->usage` is a `Prism\Prism\ValueObjects\Usage`:

| Prism | Anthropic | Note |
|---|---|---|
| `promptTokens` | `usage.input_tokens` | Input that was not read from or written to the prompt cache |
| `completionTokens` | `usage.output_tokens` | Includes thinking tokens |
| `thoughtTokens` | `usage.output_tokens_details.thinking_tokens` | Part of `completionTokens`. Do not add it |
| `cacheWriteInputTokens` | `usage.cache_creation_input_tokens` | null unless prompt caching is used |
| `cacheReadInputTokens` | `usage.cache_read_input_tokens` | null unless prompt caching is used |

`draft_runs.input_tokens` is `promptTokens` and `draft_runs.output_tokens` is
`completionTokens`. We do not use prompt caching in M4, so the cache fields are
null and the two numbers are the whole bill. If caching is added later, cache
tokens are billed at other rates and need their own columns and prices.

`$response->meta->model` is the model that answered. It can differ from the one
asked for (an alias answered by a dated name, or a server-side fallback).
`CostEstimator` looks up the price for the model that answered first and for the
model that was asked for second.

### 5. Errors

| What happens | What Prism throws |
|---|---|
| HTTP 429 | `PrismRateLimitedException` (with `retryAfter`) |
| HTTP 529 | `PrismProviderOverloadedException` |
| HTTP 413 | `PrismRequestTooLargeException` |
| Other API error | `PrismException`, message `Anthropic Error: [type] message` |
| Timeout, connection failure | Laravel's `ConnectionException` |

The first two are worth trying again later (`DraftGenerationFailed::retryable`).
These exceptions wrap the HTTP request, whose headers hold the API key, so the
generator never passes one on as `$previous`: it builds a `DraftGenerationFailed`
from the status and the error type, and gives the key as a secret to remove.

### 6. Testing

`Prism::fake([...])` replaces the manager with a fake that returns
`StructuredResponseFake` objects and records requests (`docs/core-concepts/testing.md`).
Only the tests of `PrismDraftGenerator` itself need it. Everything else uses
`tests/Fakes/FakeDraftGenerator.php` and never loads Prism.

### 7. Model and prices

Default model: `claude-opus-5-5`, Anthropic's current default recommendation,
set in `NOONGUN_DRAFT_ANTHROPIC_MODEL`. Prices per million tokens, from
Anthropic's model table as of 2026-11-03: Opus 5.5 4.00 in / 20.00 out, Sonnet
5.5 2.00 / 10.00, Haiku 5.5 0.10 / 0.50, in USD. They are in
`config/noongun.php` under `drafting.prices` and can be replaced with
`NOONGUN_DRAFT_PRICES`. They were not checked against the live price list on
the day of writing; do that before the estimate is shown to paying users.

A draft is a few thousand tokens in and a few hundred out, so the model choice
matters little for cost at Phase 1 volume. For the SaaS tiers it does, and the
model is one environment variable.

Model behaviour that affects the generator (Anthropic documentation):

- Thinking is always on for Opus 5.5 and cannot be turned off. Do not send a
  `thinking` option. Depth is steered with `effort`
  (`->withProviderOptions(['effort' => 'low'])`); the default is `medium`.
- `temperature`, `top_p` and `top_k` are rejected with a 400. Do not call
  `usingTemperature()`.
- Prefilling the assistant turn is rejected. Structured output replaces it.

### 8. Where this differs from the brief

- **Brief section 7** gives `generate(DraftRequest $request): array` returning
  one `DraftResult` per channel. One call produces one post, and link,
  alternative text and rationale belong to the post, not to a channel. The
  contract returns one `DraftResult` that holds a text per channel. See ADR 0011.
- **Brief section 9** says "validate it; on invalid output retry once". With
  native structured outputs the JSON shape is enforced by the provider, so the
  retry is mostly for what the schema cannot express (a missing channel, a text
  over the limit) and for cut-off answers. It is still needed.
- **Brief section 9** lists `gemini`, `mistral` and `ollama` as available
  "because Prism supports them". Prism does, but each provider enforces schemas
  differently (some only promise valid JSON). Each needs its own check against
  `DraftSchema` before it is offered. Only `anthropic` is configured.
- Nothing in the brief conflicts with Prism's licence (MIT) or with how keys are
  passed.
