# 0013 - A drafting provider per workspace

Date: 2026-11-25. Status: accepted.

## Context

M4 drafts with one provider for the whole instance (ADR 0011). M5 adds OpenAI
as a second one, and a workspace is to choose. That raises questions ADR 0011
did not have to answer: where the choice is kept, who may make it, what keeps
the key of one provider away from another, what happens to the limits and to
cost when the choice changes, and how much of the generator is shared.

Findings about OpenAI through Prism are in `docs/platform-notes/llm.md`.

## Decision

### The choice is one nullable column

`workspaces.drafting_provider` holds the name of the provider a workspace is
settled on. Null means "the default of the instance"
(`NOONGUN_DRAFT_PROVIDER`), so nothing is migrated.
`DraftGenerators::providerFor()` stays the one place that decides, and
`choose()` and `settleOn()` in the same class are the only places that write.

A settings table was not added: there is one value, and it belongs to the
workspace row.

### The operator offers, the owner chooses

`NOONGUN_DRAFT_PROVIDERS` lists the providers a workspace may choose from.
The default provider is always offered. A second provider is offered only
when the operator lists it: it means that briefs go to another company, which
is the operator's decision to allow before it is an owner's to make.

Choosing is for owners, behind a recently confirmed password and the throttle
of the key form, because it decides who is sent the brief and whose account
pays.

### A workspace that has used a provider stays with it

Null is for a workspace that has not used drafting. The column is filled, by
`DraftGenerators::settleOn()`, at the first of these:

- an owner chooses a provider;
- an owner stores a key for the provider in use (a key for another provider
  settles nothing: it is not in use);
- a draft of the workspace is about to be asked of a provider, in
  `GenerateDraft`, after every check that can still stop it without a call.

From then on the operator changing `NOONGUN_DRAFT_PROVIDER` does not move the
workspace. An earlier version left such a workspace on null, and a change of
the default sent its brief to another company, on the instance key, without
anyone in the workspace doing anything. That was the same harm the next
section prevents for an explicit choice, so it gets the same protection.

The first draft is the moment used, and not only the key, because a workspace
on the instance key stores no key and still hands its brief to a vendor.
Looking at a page, a plan or a cost settles nothing, and neither does a run
that ends before a provider is called.

There is no way back to "follow the default": once a workspace is settled,
another provider is an owner's choice, behind a confirmation that says what
is sent, who pays and what happens to drafts in progress. The settings page
says which of the two states the workspace is in.

### A choice that is no longer offered stops drafting

When a workspace chose a provider and the operator takes it off the list,
`providerFor()` throws. The workspace is not moved to the default provider:
its content would go to a company it did not choose. The settings page says
so and an owner chooses again. Because an owner can put this right, members
read a sentence of its own on the plan page and on a failed run
(`DraftGeneratorNotAvailable::CHOICE_NOT_OFFERED`, refusal reason
`provider_choice` with a link to the settings), and not the sentence that
sends them to the operator.

### A key belongs to one provider

`llm_credentials` was keyed by provider from the start (ADR 0011), and the
order of ADR 0011 (workspace key, stored instance key, environment) holds for
each provider by itself. An unreadable workspace key still ends the search
for that provider. On top of the lookup:

- an `LlmKey` carries its provider, and a `DraftingContext` whose key belongs
  to another provider cannot be constructed;
- a generator reads the value with `LlmKey::revealFor($provider)`, which
  throws for another provider;
- the key form refuses a key that starts the way another provider's keys do.

For OpenAI, Prism reads an organization and a project for the whole instance
from the environment and sends them as headers. They belong to the operator's
account, so `PrismDialect::connectionOptions()` takes them out of a call that
uses a key a workspace brought.

A workspace can hold a key for each provider. Removing a key is possible for
any provider it holds one for, also one that is no longer offered.

### One generator, one place for differences

`PrismDraftGenerator` serves both providers. What differs is in
`Generators\PrismDialect`: the provider options of a request (for OpenAI a
strict schema, `store: false` and effort as `reasoning.effort`) and the error
names that mean "try again" or "out of credit". The schema, the prompt, the
validation, the retry after an invalid answer and the messages are shared.
The answer of either provider is untrusted and goes through
`DraftResult::fromArray()`.

`Generators\ProviderReplies` reads two things from the HTTP answer that
Prism drops: the error type and code of a 429, and the usage of an answer
that was cut off. It keeps nothing of the request.

### Limits do not look at the provider

`DraftBudget` counts runs of a workspace, and runs on instance keys, whatever
provider they used. Changing the provider therefore opens no new allowance. A
run that was opened for one provider is not drafted with another:
`DraftingContextChanged` (ADR 0012) already compares the provider.

### Cost follows the run

A run stores provider and model when it is opened, and its estimate when it
ends, so history does not change with the choice. Input that went through a
provider's prompt cache is part of `input_tokens` from now on, and is priced
with `cached_input` and `cache_write` of the model when the price table has
them. The worst case before a proposal counts all input at the dearest input
price (`CostEstimator::atMost()`).

## Consequences

- With `NOONGUN_DRAFT_PROVIDERS` empty the page shows one provider. An
  existing workspace is settled on the default at its next draft.
- An operator who changes `NOONGUN_DRAFT_PROVIDER` moves only workspaces that
  never drafted. To move the others, an owner of each chooses, or the
  operator stops offering the old provider and they stop until one does.
- A workspace cannot return to following the default. If that is wanted
  later it is a deliberate action for an owner, not a default.
- An operator who removes a provider from the list stops drafting for the
  workspaces that chose it until an owner acts. That is intended, and it is
  the one way a workspace can lose drafting without doing anything.
- The instance cap is one number for all providers. An operator who pays for
  two providers cannot give each its own cap; metering by cost is M8.
- `draft_runs.input_tokens` now includes cached input. For Anthropic that
  changes nothing while caching is not asked for.
- The cache figures of a run are not stored, only the estimate made from
  them. Storing them needs two columns and is left until it is asked for.
- `max_tokens` and the timeout are one setting for all providers. OpenAI
  recommends more room for reasoning models than our default; see the
  platform notes.
- The key prefixes are a convenience check from the configuration, not a
  guarantee. The guarantee is the lookup by provider and the two checks in
  code.
- The reading of HTTP answers depends on the event the Laravel HTTP client
  fires. Without it a 429 for an empty account is reported as a rate limit
  and retried up to `max_attempts` times, and a cut off answer is recorded
  without usage.
