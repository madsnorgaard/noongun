# 0011 - The drafting contract

Date: 2026-11-25. Status: accepted.

## Context

M4 adds drafting: a brief, pillars, a generator behind `DraftGenerator`, "propose a
week", and cost recording. It is built by several tracks at once (the brief and
pillar pages, the Prism generator, the week proposal), so the shapes they share
are fixed here first. The brief (sections 6, 7 and 9) names the tables and the
interface and leaves open how a brief is versioned, where an instance key lives,
how cost is worked out, and what exactly a generator returns.

Findings about Prism and Anthropic are in `docs/platform-notes/llm.md`.

## Decision

### One request, one post, one run

A `DraftRequest` asks for one post, written once for each target channel. A
`DraftResult` holds a text per channel plus one link, one alternative text and
one rationale. A `draft_runs` row is one such generation. A week of five posts
is five requests, five runs and five posts.

The brief sketches `generate()` as returning an array of results, one per
channel. That is changed: link, alternative text and rationale belong to the
post, and the provider is asked once for all channels, so one result is the
honest shape. The signature is
`generate(DraftRequest $request, DraftingContext $context): DraftResult`.

`DraftingContext` carries provider, model, token limit, timeout, prompt version
and the key. A generator is stateless and takes all of these from the context,
so no model name or limit can live in a generator.

### Generated posts are drafts

`CreateDraftFromResult` is the only code that turns a result into a post, and it
does so through `SaveDraft`, the path a hand-written post takes. The post is in
the `draft` state without an approval. A time given to it is the planned time of
the draft (ADR 0009) and schedules nothing. Nothing under `app/Domain/Drafting`
may approve, schedule, retry or publish;
`tests/Feature/Drafting/GeneratedPostsStayDraftsTest.php` holds that line.

Recording the result on the run and creating the post are one transaction, and
the run moves from `running` to `succeeded` with one conditional `UPDATE`. A
result that is delivered twice creates one post.

`posts` gets three nullable columns, `draft_run_id`, `pillar_id` and `idea_id`,
written once by `Post::attributeTo()`. Deleting a run, a pillar or an idea keeps
the post and clears the column.

### Draft run states

`pending -> running -> succeeded`, and `pending` or `running -> failed`. Each move
is one conditional `UPDATE` (`markRunning()`, `markSucceeded()`, `markFailed()`),
as for posts. `pending` exists so that a run is visible while its job waits, and
so that a job delivered twice runs once.

A run stores what was asked for (provider, model, prompt version, brief, pillar,
idea, a free-form `request` note, who asked, whose key) and what came back
(attempts, tokens, cost, rationale, alternative text, or the reason it failed).
It never stores the prompt or the key. A failed run keeps the tokens of its
attempts when the provider reported them, because they were paid for.

### Validation and the retry

`DraftSchema::definition()` is the one JSON schema. The generator sends it to
the provider, and `DraftResult::fromArray()` checks the answer against the same
definition and then against the request: one text for each requested channel and
no other, no empty text, no text over the platform's limit, a link that is an
http or https address. A `DraftResult` can only be built this way, so one that
exists is valid.

Invalid output throws `InvalidDraftOutput`. The generator asks once more and
then lets the exception out with the usage of both attempts; `GenerateDraft`
marks the run failed with the reason. The retry lives in the generator because
only it knows what an attempt cost.

The schema uses only what providers enforce natively (closed objects, every
property required, null instead of optional, no length limits). Limits that
depend on the request are checked by us.

### Brief versioning

`briefs` holds every version. Saving adds a row with the next `version` for the
workspace and makes it the current one; earlier rows are never edited
(`Brief::saveNewVersion()`, or `SaveBrief` from a controller). A partial unique
index allows one current row per workspace. A draft run points at the version it
was generated from, so "what did the model see" has an answer later.

There is no draft or unpublished brief: what is saved is what the next
generation uses. There is no restore action yet; saving an old text again makes
it a new version.

Voice rules are data: `{general: [...], platforms: {facebook: [...]}}`, read as
a `VoiceRules` object. Calls to action are a list of `{text, url}`.

### Keys

A workspace uses, for its provider, the first key that exists:

1. its own key in `llm_credentials` (bring your own key);
2. the instance key stored in `llm_credentials`;
3. the instance key from the environment (`ANTHROPIC_API_KEY`, read into
   `noongun.drafting.providers.anthropic.api_key`).

When there is none, `LlmCredentialMissing` says so in words a user can act on.
The scope of the key that was used is stored on the run as `credential_scope`,
which is what metering needs to tell "we paid" from "they paid".

`llm_credentials.workspace_id` is null on an instance key. This is the one
exception to "every table except `users` carries `workspace_id`": an instance
key is the operator's and belongs to no tenant. It is contained as follows.

- A check constraint ties `scope` to `workspace_id`: a workspace row has a
  workspace, an instance row has none.
- `LlmCredential` is the tenant model. It uses the workspace scope like every
  other tenant model, so it never sees or writes an instance row.
- `InstanceLlmCredential` is a second model on the same table that only sees
  instance rows. Only `LlmCredentialResolver` reads it.

Keys are encrypted at rest (Laravel encrypted cast) and hidden from arrays and
JSON. In memory a key travels as an `LlmKey`, which does not print its value and
refuses to be serialized, so it cannot reach a log, a queue payload or a cache.
A job resolves the key again when it runs.

The stored instance key goes before the environment so that an operator can
later change it from the interface without a restart. In Phase 1 only the
environment key is used; nothing writes an instance row yet.

### Exceptions

`DraftingException` follows `ChannelException`: a safe message, `retryable`,
context that is cleaned of credentials, and no `$previous`, because vendor and
HTTP exceptions carry request headers. It also takes the key as a secret to
remove, and carries the usage of the failed attempts. Subclasses:
`DraftGenerationFailed`, `InvalidDraftOutput`, `LlmCredentialMissing`.

### Cost

`draft_runs.cost_estimate` is tokens times the price per million tokens from
`noongun.drafting.prices`, keyed by provider and model, in
`noongun.drafting.currency`. The price is looked up for the model that answered
and, when that has none, for the model that was asked for. A model without a
price gives a run with tokens and no cost; it does not fail the run.

It is an estimate. It ignores prompt caching (not used in M4) and anything the
provider bills that is not a token. The currency is stored on each run so that
a later change of the price table does not rewrite history.

### Prompts

Templates are Blade files in `resources/prompts/draft/<version>/system.blade.php`
and `user.blade.php`, registered as the view namespace `prompts`. The version is
the folder name, comes from `NOONGUN_DRAFT_PROMPT_VERSION`, and is stored on each
run. A version that runs refer to is not edited; a change is a new folder.

`PromptRenderer::render()` is the only place that decides what a provider is
sent. It gives the templates `DraftRequest::toArray()` and nothing else, and a
`DraftRequest` holds plain values copied out of the workspace's records: the
brief, the voice rules, the pillar, the idea, a source URL, and for each channel
its id, platform and limits. Channel names, tokens, user data and other
workspaces' content are not in it. `tests/Feature/Drafting/PromptRendererTest.php`
holds that line.

### Slots

`slots.time` is a wall-clock time in the workspace timezone, the one time in the
application that is not stored in UTC. A slot is a habit ("Tuesdays at nine"),
not an instant; stored in UTC it would move by an hour twice a year in
Copenhagen. `Slot::occurrenceInWeekOf()` and `nextOccurrence()` turn it into a
UTC instant through `Workspace::toUtc()`, so a skipped or repeated hour behaves
as on the calendar (ADR 0010). `weekday` is the ISO number, Monday 1 to Sunday 7.

### Where the models live

Following the brief's directory plan: `Pillar` and `Idea` in `Content`, `Slot`
in `Scheduling`, `Brief`, `DraftRun` and the credential models in `Drafting`.

### Pinned version

`prism-php/prism` 0.103.0, as `^0.103` in `composer.json`, by the rule of ADR
0006. Below 1.0 the caret allows patch releases only.

## Consequences

- The interface differs from the sketch in the brief. Brief section 7 should be
  read with this record.
- A provider is chosen for the whole instance (`NOONGUN_DRAFT_PROVIDER`).
  `DraftGenerators::providerFor($workspace)` is the single place a per-workspace
  choice is added in M5.
- Whether a workspace on a bring-your-own-key plan may fall back to the instance
  key is an entitlement question for M8. Today every workspace may.
- Alternative text is stored on the run and not used further, because posts
  have no media yet.
- One post per run means a week costs one call per post. Asking for a whole
  week in one call would be cheaper in input tokens and is not done: one failed
  post would fail the week, and runs would stop matching posts.
- The cost estimate is as good as the price table. Prices change; the table is
  configuration, and old runs keep the estimate they were given.
- Deleting a brief version, a pillar or an idea is allowed and leaves runs and
  posts without that link. Nothing offers deleting a brief version.
- `ContentPolicy` is one policy for the models that need no rule of their own.
  LLM keys have a stricter one: owners only.
