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

## 2026-11-25 - OpenAI as a second provider (issue #5)

Read from the installed package (Prism v0.103.0,
`vendor/prism-php/prism/src/Providers/OpenAI`) and from OpenAI's
documentation on `developers.openai.com/api/docs` (where
`platform.openai.com/docs` now redirects) on 2026-11-25: the pricing page,
the models page, and the guides for structured outputs, reasoning, prompt
caching, error codes and data controls. The API reference page for creating
a response was too long to be read in full; what is said below about request
fields comes from the guides and from Prism's source.

### 1. The call

Prism's OpenAI provider uses the **Responses API**: `POST /v1/responses` with
`Authorization: Bearer <key>`. For our request it sends:

```json
{
  "model": "...",
  "input": [
    {"role": "system", "content": "<system prompt>"},
    {"role": "user", "content": [{"type": "input_text", "text": "<user prompt>"}]}
  ],
  "max_output_tokens": 16000,
  "tools": [],
  "parallel_tool_calls": false,
  "store": false,
  "text": {"format": {"type": "json_schema", "name": "post_draft", "schema": {}, "strict": true}}
}
```

and `"reasoning": {"effort": "..."}` when an effort is configured. A test
asserts the whole body and the headers.

- **Token limit.** `withMaxTokens()` becomes `max_output_tokens`. It includes
  reasoning tokens.
- **Key.** The per-call `api_key` of `using()` becomes the bearer token, as
  for Anthropic. Prism's own configuration also reads `OPENAI_API_KEY`,
  `OPENAI_URL`, `OPENAI_ORGANIZATION` and `OPENAI_PROJECT` from the
  environment; the per-call key replaces the first. The organization and
  project headers are sent only when those variables are set, and they then
  apply to every workspace, also one with its own key. Leave them unset.
- **Strict.** Prism sends `strict` only when the provider option
  `schema.strict` is given. We give `true`.
- **Store.** The Responses API keeps a response as conversation state for 30
  days unless told otherwise. We never continue a conversation, so `store` is
  `false`. Abuse monitoring logs are a separate matter and are kept for up to
  30 days by default; API data is not used for training unless the account
  opts in (data controls guide).
- **Temperature.** Not sent, as for Anthropic.
- **Structured mode.** Prism picks JSON mode for `gpt-3.5-turbo`, `gpt-4`
  and `gpt-4-*`, and refuses `o1-mini` and `o1-preview`. Every other model
  name gets `json_schema`. Do not configure one of the old ones.

Everything that differs from the Anthropic call is in
`App\Domain\Drafting\Generators\PrismDialect`.

### 2. The strict schema

The rules for a strict schema (structured outputs guide, "Supported
schemas"), and how `DraftSchema::definition()` stands:

| Rule | `DraftSchema` |
|---|---|
| The root is an object and not an `anyOf` | yes |
| Every object has `additionalProperties: false` | yes, both objects |
| Every property is listed in `required` | yes |
| An optional value is a union with `null` | `link` and `alt_text` are `anyOf` of string and null |
| Types: string, number, boolean, integer, object, array, enum, `anyOf` | only these |
| Not supported: `allOf`, `not`, `dependentRequired`, `dependentSchemas`, `if`, `then`, `else` | none used |
| At most 5000 properties and 10 levels of nesting | 6 properties, 3 levels |

The guide's example of an optional value uses `"type": ["string", "null"]`;
`anyOf` with a null branch appears in its example of a recursive schema and
`anyOf` is a supported type, so the schema is left as it is. It was not sent
to the live API. `tests/Feature/Drafting/OpenAiDraftingTest.php` walks the
schema against these rules, so a change that breaks one fails a test.

The schema is the same one for both providers. Strict mode holds a finished
answer to it; it does not replace our check. The answer of either provider
goes through `DraftSchema::problems()` and `DraftResult::fromArray()`, and
what the schema cannot say (which channels, how long) is checked there.
Output keys come in the order of the schema.

### 3. Answers that are not a draft

| What OpenAI answers | What Prism gives | What the generator does |
|---|---|---|
| `status: completed`, a message with `output_text` | `FinishReason::Stop`, `structured` decoded | validates |
| a message part of type `refusal` | `FinishReason::Refusal`, `structured` empty | fails at once, permanent. The text of the refusal is not used |
| `status: incomplete`, reason `content_filter` | `FinishReason::ContentFilter` | as a refusal |
| `status: incomplete`, reason `max_output_tokens` | **throws** `PrismException` ("max tokens exceeded"), without the usage | fails at once, permanent, as a cut off answer, with the usage read from the answer (see 5) |
| `status: failed` with an `error` object | **throws** `PrismException` ("OpenAI Error: [unknown] ...") | `server_error` and `rate_limit_exceeded` retryable, others permanent |
| `status: completed`, text that is not JSON | **throws** `PrismStructuredDecodingException` with the text in its message | counts as an invalid answer and is asked for once more; the text is not kept |

The last row holds for every provider: `Structured\ResponseBuilder` decodes
the text of a finished answer and throws when it cannot. The earlier entry
("Invalid JSON does not throw") was true for the strategy class and not for
the response as a whole.

The reasoning guide notes that an answer can reach `max_output_tokens`
before any visible text, and that input and reasoning tokens are billed all
the same. It recommends room for at least 25,000 tokens when starting out.
Our default is 16,000 for both providers.

### 4. Usage

| Prism | OpenAI | Note |
|---|---|---|
| `promptTokens` | `usage.input_tokens` minus cached and cache write tokens | Prism subtracts, because OpenAI's `input_tokens` includes them |
| `cacheReadInputTokens` | `usage.input_tokens_details.cached_tokens` | |
| `cacheWriteInputTokens` | `usage.input_tokens_details.cache_write_tokens` | |
| `completionTokens` | `usage.output_tokens` | includes reasoning tokens, which are billed as output |
| `thoughtTokens` | `usage.output_tokens_details.reasoning_tokens` | part of `completionTokens`. Do not add it |

OpenAI caches prompts by itself from 1,024 tokens on (prompt caching guide),
and a week of drafts shares most of its prompt. So `promptTokens` alone would
under-count. The generator now records **all** input as
`draft_runs.input_tokens` (prompt plus cache read plus cache write), for
every provider, and passes the two cache figures to the cost estimate. For
Anthropic they are zero as long as we do not ask for caching. This replaces
section 4 of the first entry, which recorded `promptTokens` only.

Cached input is billed at other prices: a read at 0.1 times the input price
on most current models (0.05 on some), a write at 1.25 times for the default
lifetime of 30 minutes on GPT-5.6 and later. The price table can hold
`cached_input` and `cache_write` per model; without them such tokens count
at the input price. The two cache figures are not stored on the run, only
the estimate that was worked out from them.

### 5. Errors

Prism maps an HTTP error of OpenAI like one of Anthropic: 429 to
`PrismRateLimitedException`, 529 and 413 to their own classes, every other
status to `PrismException` with the status as code, the message
`OpenAI Error [status]: type - message` and the HTTP exception as
`$previous` (which holds the bearer token, so it is never passed on).

Two things are lost on the way, and the generator reads them from the HTTP
answer itself (`Generators\ProviderReplies` listens to the HTTP client only
while a call to a provider runs, and keeps a few plain fields, nothing of
the request):

- **Why a 429.** OpenAI answers 429 both for "too fast" and for "out of
  credit" (error codes guide). Too fast (`rate_limit_error`, code
  `slow_down` or a rate limit) is worth trying again. Out of credit is not:
  type `insufficient_quota`, or the codes `credit_balance_exhausted`,
  `organization_spend_limit_exceeded`, `project_spend_limit_exceeded`,
  `organization_usage_limit_exceeded`. "Retrying billing, spend, or quota
  errors won't restore API access."
- **What a cut off answer used.** See the table in 3.

| What happens | How the generator reports it |
|---|---|
| 401 (wrong, revoked or foreign key, IP not allowed) | permanent, "did not accept the API key" |
| 403 (country not supported) | permanent, "not allowed to do this" |
| 404 (model unknown or not available to the key) | permanent, names the operator |
| 400 (a schema or a parameter the API refuses) | permanent, the provider's words in the operator detail |
| 429, too fast | retryable, with `retry_after` |
| 429, out of credit or over a spending limit | permanent, "no credit left or a billing problem" |
| 500, 502, 503 (`server_is_overloaded`) | retryable |
| Timeout, connection failure | retryable |

The error names are in `PrismDialect`. `billing_not_active`,
`server_error` and `rate_limit_exceeded` are on its lists from memory of
earlier versions of the API and were not found in the pages read; they do no
harm if they never occur.

### 6. Model and prices

From the pricing page and the models page, 2026-11-25, USD per million
tokens, standard tier, prompts up to 272,000 tokens:

| Model | Identifier | Input | Cached input | Output |
|---|---|---|---|---|
| GPT-6.2 Astra | `gpt-6.2-astra` | 8.00 | 0.40 | 40.00 |
| GPT-6.2 Sol | `gpt-6.2-sol` | 2.00 | 0.10 | 10.00 |
| GPT-6.1 Terra | `gpt-6.1-terra` | 1.00 | 0.05 | 5.00 |
| GPT-6 Luna | `gpt-6-luna` | 0.10 | 0.01 | 0.50 |

These four are the "flagship models" of the models page and are in the
price table. `cache_write` in the table is 1.25 times the input price, worked
out from the prompt caching guide and not read from the price list.

Default: **`gpt-6.2-sol`**, "our go-to model for everyday work". The models
page says "If you're not sure where to start, use GPT-6.2 Astra", which costs
four times as much; a post of a few hundred words does not need it, and the
model is one variable (`NOONGUN_DRAFT_OPENAI_MODEL`). This differs from the
Anthropic default, which is that vendor's first recommendation.

Reasoning effort (`reasoning.effort`): `none`, `minimal`, `low`, `medium`,
`high`, `xhigh`, `max`, depending on the model. GPT-6.2 Sol and both Astra
models reject `none`. The page names `medium` as the default for several
models and does not say what GPT-6.2 Sol does without a value.
`NOONGUN_DRAFT_OPENAI_EFFORT` is empty by default, which sends nothing.

### 7. Keys

An OpenAI key is sent as a bearer token, an Anthropic key in `x-api-key`. A
key is stored per provider and looked up by provider, an `LlmKey` knows its
provider, a `DraftingContext` cannot be built with a key of another
provider, and the generator reads the value with `revealFor($provider)`.

The key form also refuses a key whose beginning is that of another
provider's keys (`key_prefix` in the configuration: `sk-ant-` for Anthropic,
`sk-` for OpenAI; the longest prefix that fits decides). The prefixes are
from the keys in use today and from no documentation page. If a provider
changes how its keys start, change `key_prefix` in `config/noongun.php`.

### 8. Testing

The tests of the OpenAI path fake the HTTP client, not Prism, so Prism's own
OpenAI provider runs: the request body, the headers and the reading of each
kind of answer are real. The answers are written by hand in the shape of the
Responses API. No answer was recorded from the live API.
