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

## 2026-11-25 - building `PrismDraftGenerator`

Read from the installed package (v0.103.0) while writing the generator, and
checked against Anthropic's documentation on platform.claude.com the same day.
Where this differs from the entry above, this is the newer finding.

### 1. The call as built

```php
Prism::structured()
    ->using($context->provider, $context->model, ['api_key' => $context->key->reveal()])
    ->withSchema(new RawSchema(DraftSchema::NAME, DraftSchema::definition()))
    ->withSystemPrompt($prompt->system)
    ->withPrompt($prompt->user)
    ->withMaxTokens($context->maxTokens)
    ->withClientOptions(['timeout' => $context->timeoutSeconds])
    ->withProviderOptions(['effort' => $effort])   // only when an effort is configured
    ->asStructured();
```

Read from the response: `structured`, `finishReason`, `usage->promptTokens`,
`usage->completionTokens`, `meta->model` and
`additionalContent['stop_details']['category']`.

- `using()` resolves the provider at once, so it can throw
  (`InvalidArgumentException` for a provider Prism does not know). It is inside
  the same `try` as the call.
- Prism does not retry by itself: `clientRetry` is `[0]` unless
  `withClientRetry()` is called. We do not call it. Trying again later is the
  caller's decision, from `DraftGenerationFailed::retryable`.
- Nothing else is set: no tools, no provider tools, no messages besides the one
  user prompt, no temperature. A test asserts the whole request.

### 2. Errors, corrected

The table in section 5 above is incomplete. What the Anthropic provider throws
(`src/Providers/Anthropic/Anthropic.php`, `Concerns/HandlesHttpRequests.php`):

| What happens | What Prism throws | How the generator reports it |
|---|---|---|
| HTTP 429 | `PrismRateLimitedException`, `retryAfter` from the header | retryable |
| HTTP 529 | `PrismProviderOverloadedException` | retryable |
| HTTP 413 | `PrismRequestTooLargeException` | permanent |
| Any other HTTP error | `PrismException`, message `Anthropic Error [status]: type - message`, the status as `getCode()`, **the HTTP exception as `$previous`** | 5xx retryable; 401, 403, 404 and billing with a message of our own; other 4xx permanent with the provider's message |
| HTTP 200 with an error body | `PrismException`, message `Anthropic Error: [type] message`, no status | `overloaded_error`, `rate_limit_error`, `api_error` and `timeout_error` retryable, others permanent |
| Timeout, connection failure | Laravel's `ConnectionException` | retryable |
| Anything else | any `Throwable` | permanent, with the class name only |

The fourth row is why a vendor exception is never passed on: its `$previous`
holds the request, and the request holds the `x-api-key` header. The provider's
message is kept for a 4xx, passed through `DraftingException::redact()` with the
key as a secret, and cut to 300 characters.

A 5xx and a connection failure are reported as retryable, next to the rate limit
and the overload. They are faults of the provider or the network, and the same
request can succeed later.

### 3. Answers that are not a draft

| `finishReason` | Anthropic `stop_reason` | What the generator does |
|---|---|---|
| `Stop`, `Other`, `Unknown` | `end_turn`, `stop_sequence`, one Prism does not know | validates the answer |
| `Refusal`, `ContentFilter` | `refusal` | fails at once, permanent. The category from `stop_details` goes into the context, the explanation is not used |
| `Length` | `max_tokens`, `model_context_window_exceeded` | fails at once, permanent. The member reads a plain sentence; the limit and `NOONGUN_DRAFT_MAX_TOKENS` are in the operator detail |
| `ToolCalls`, `Pause`, `Error` | `tool_use`, `pause_turn` | fails at once, permanent. Cannot happen without tools; handled so that it is visible if it does |

A cut off answer is **not** asked for again, unlike what section 8 above says:
the same request against the same limit ends the same way and costs a full
answer. The second request is for answers that finished and do not pass
`DraftResult::fromArray()`.

The message of a failure is stored on the run and read by every member of the
workspace, so it names no setting, model, status code or error type. What the
operator needs (the setting to change, the model that was asked for, what the
provider said) is in the context under `operator_detail`
(`DraftingException::OPERATOR_DETAIL`, read with `operatorDetail()`). It is
redacted like all context and is meant for the log, not for a page. Validation
problems name a channel by its platform ("The text for Facebook is ..."),
never by its id.

Two answers in a row that are empty (`structured` is `null` or `[]`, which is
also what broken JSON becomes) are reported as `DraftGenerationFailed` with a
message of their own, not as `InvalidDraftOutput` with a list of missing fields.

Usage is summed over both attempts whatever the outcome, and a failure of the
second call keeps the usage of the first.

### 4. What the answer can reach

The output is untrusted. Followed through the code on 2026-11-25:

- `variants[].body`, `alt_text`, `rationale`: stored as text. No page prints
  post text with `v-html`; Vue's text interpolation escapes it. A page that
  shows the rationale or the alternative text must do the same.
- `link`: kept only when it is the request's source URL or the URL of a call
  to action of the brief, both of which the author wrote and both of which must
  be http or https (`DraftResult::fromArray()`, see ADR 0011). Anything else
  is dropped, the draft has no link, and `draft_runs.link_dropped` is set. The
  run does not fail for it. The comparison is deliberately plain: trim, scheme
  and host in lower case, an empty path as `/`, the rest exactly as written.
  So `https://allowed.example@evil.example/`, a lookalike host, another path,
  an added query or another port are all dropped. The page builds the `href`
  through `webAddress()`, which checks the scheme again. The server never opens
  the address; the Facebook adapter passes it to the Graph API as a parameter.
  The prompt (v1 and v2) already tells the model to take the link from the source URL
  or a call to action and never to make one up, so its wording is unchanged.
- `variants[].channel_id`: an integer that must be one of the requested
  channels, and those were read from the current workspace when the request
  was built. An id the model makes up fails validation before anything is
  saved.
- `meta->model` (set by the provider, not by the model): stored on the run and
  used as a key in the price table. The generator keeps it only when it looks
  like a model name (128 characters of letters, digits and `._:/@-`), and
  records the model that was asked for otherwise.
- A NUL character in any text cannot be stored by PostgreSQL. The generator
  treats such an answer as invalid, so it is asked for again instead of failing
  the run with a database error.

Nothing from the output reaches a header, a file path or a query that is not
parameterised.

One thing was open on our side and is closed: an API key goes into the
`x-api-key` header, so a stored key with a line break in it could split the
header. The key form accepts printable ASCII without spaces only.

### 5. Effort

`NOONGUN_DRAFT_ANTHROPIC_EFFORT` (`drafting.providers.anthropic.effort`) is
passed as the `effort` provider option, which Prism sends as
`output_config.effort` next to the format. Empty sends nothing, and the default
of the model applies (`medium` on Opus 5.5, see section 7 of the entry above).
Only a single lower case word is accepted from configuration.

### 6. Not used

- **Server-side fallbacks.** Prism supports
  `->withProviderOptions(['fallbacks' => 'default'])`, which lets Anthropic
  answer a declined request with another model in the same call (it adds a beta
  header). It is not used: a refusal is reported to the person who asked. If it
  is added later, `meta->model` is the model that answered, and the cost
  estimate already looks its price up first.
- **Prompt caching, streaming, tools.** Not needed for one short answer.

### 7. Testing

`Prism::fake()` swaps `PrismManager` for one that returns the fake for every
provider name, so the provider name is not checked and no provider class is
built. `assertProviderConfig()` sees the per-call config, which is how the test
proves the key was passed per call. The fake cannot throw, so the tests of
failed calls swap `PrismManager` the same way for a provider that throws what
Prism would throw.

### 8. Model and prices, checked

Checked on 2026-11-25 against Anthropic's pricing page
(`platform.claude.com/docs/en/about-claude/pricing`) and model pages:

| Model | Identifier | Input | Output | `config/noongun.php` |
|---|---|---|---|---|
| Claude Opus 5.5 | `claude-opus-5-5` | 4.00 | 20.00 | same |
| Claude Sonnet 5.5 | `claude-sonnet-5-5` | 2.00 | 10.00 | same |
| Claude Haiku 5.5 | `claude-haiku-5-5` | 0.10 | 0.50 | same |

USD per million tokens. No default was changed. Two things the table does not
express:

- Haiku 5.5 costs 0.50 in and 2.50 out for a request whose prompt is over
  100,000 tokens. A draft prompt is a few thousand tokens, so the lower price
  is the right one here.
- Models from Claude 4.7 on use a tokenizer that counts roughly 30 percent more
  tokens for the same text than earlier models. The estimate uses the tokens
  the provider reports, so it is not affected, but a comparison of prices per
  token across model generations is.
