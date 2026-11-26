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
`DraftResult` holds a text per channel plus one link, a title and a description
for the card of that link (since M5, see below), one alternative text and one
rationale. A `draft_runs` row is one such generation. A week of five posts
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
no other, no empty text, no text over the platform's limit. A `DraftResult` can
only be built this way, so one that exists is valid.

The link is the one part of the answer that is not checked but replaced. A link
is kept only when it is the source URL of the request or the URL of one of the
brief's calls to action, compared after trimming, with scheme and host in lower
case and an empty path read as `/`, and everything else exactly as written. The
result then carries the address in the author's spelling. Any other link is
dropped and the draft is created without one; `draft_runs.link_dropped` records
that it happened. A run does not fail for it. The reason: an idea or a source
can hold text that someone else wrote, that text is sent to the model, and a
model that follows it must not be able to put an address of that someone's
choosing on a post that may then be approved in a batch.

#### The card of the link (added in M5)

Some platforms show a link only with a title the author gives
(`ChannelCapabilities::needsLinkCard()`, ADR 0015), and the readiness check
refuses a variant on such a channel that has a link and no title. A drafted
post with a link would then never be approvable from the queue. So the answer
carries two more fields, `link_title` and `link_description`, each a text or
null, and both are required by the schema like every other property.

They are treated like the link: never a reason to fail a run, and never stored
as the model gave them without a look.

- They are used only when the link was kept. A dropped link takes its card
  with it.
- They are stored only on the variants of channels that need a card. Any other
  channel gets none.
- Each is made one line of plain text: control characters and line breaks
  become a space.
- The title is what the model wrote. When that is empty, it is the title of
  the idea. When there is no idea either, the variant has no title and the
  readiness check asks the member for one; the draft is still created.
- A title or a description longer than the channel takes is cut to that
  limit, at a word where there is one in the second half. A channel without a
  description limit gets no description.

`TargetChannel::toArray()` gains `link_title_length` and
`link_description_length`, so that a prompt can say which channel needs the
title and how long it may be. Prompt v2 does. v1 is not edited and does not
mention the fields; a run on v1 still answers in the schema, and when it
leaves the title null the title of the idea is used.

Nothing records on the run that a title was cut or taken from the idea. The
member reads the title in the editor and in the queue before approving.

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
brief, the voice rules, the pillar, the idea, a source URL, for each channel
its id, platform and limits, and since issue #22 texts the workspace approved
and a few words about the other posts of the week (see "Examples from
approved posts"). Channel names, tokens, user data and other
workspaces' content are not in it. `tests/Feature/Drafting/PromptRendererTest.php`
holds that line.

### Examples from approved posts (brought forward from M6)

Added with issue #22. `DraftRequest::$examples` was an empty place kept for
M6, to be filled with the posts that did well. Two things were seen before M6:
drafts on the same pillar were written blind to each other and repeated, and
an edit made before approving was never picked up. So a first version is
built without metrics: the texts the workspace approved, as it approved them.
M6 added the choice by performance; see "Chosen for how they did" at the end
of this section, which changes which texts are taken and the order in which
they are left out, and nothing else described here.

Selection (`Actions\SelectExamplePosts`, reading through
`Content\ApprovedTexts`):

- A text qualifies while its post carries a human approval: `approved`,
  `scheduled`, `publishing`, `published` or `failed`. `failed` and a variant
  whose outcome is unknown qualify, because a person signed off the text and
  it is not the text that failed. `draft`, `rejected` and a post that was
  returned to draft do not: `returnToDraft()` erases the approval (ADR 0009),
  so "approved" is read from the row as it is now, and no history is kept or
  needed.
- Recent means by `posts.approved_at`, newest first.
- For each target channel the last `NOONGUN_DRAFT_EXAMPLES` texts of that
  channel (default 5, `0` turns examples off, never more than 10). A channel
  with fewer is topped up with texts of other channels of the workspace on
  the same platform, and then it stops. A text is in a request once, one post
  gives one channel one text, and a top-up that repeats a text already chosen
  word for word is left out.
- All examples of a request hold at most `NOONGUN_DRAFT_EXAMPLES_MAX_CHARS`
  characters together (default 6000, never more than 40000). Over that, the
  text with the oldest approval is left out, then the next. A text is never
  cut: half a post is a bad example of a voice.
- They are chosen when the job of a run builds its request, not when the run
  is opened.

What an `ExamplePost` carries: the text, the platform, the name of the pillar
the post had (at most 60 characters), and the id of the channel only when
that channel is a target of the same request, which already names it. It
does not carry: the id of the post, the link or its card, the author or the
approver, any time, the permalink, metrics, or the id of a channel the
request does not name. (Since M6 it also carries whether the text was chosen
because it did well; still no numbers.) Whether a human changed the text before approving is
not sent either: nothing records it reliably today (a run does not keep the
text the model gave), and edit tracking was not built for this.

Reading is workspace-scoped twice: `PostVariant` through the workspace scope,
and the join to `posts` on `workspace_id` as well as on the id. The composite
foreign keys already refuse a variant that names a post or a channel of
another workspace.

In the prompt (v2, edited in place because no run refers to it yet): the
examples are printed in the user part inside `<example_posts>`, each in an
`<example>` with its platform, channel and pillar as attributes. The lines
that say what they are stand directly before them, in the user part and not
in the system part, and only when there are examples: the system part is the
same for every request (a test holds that), and with no examples the prompt
does not mention them. Those lines say that the author approved the posts,
that they show the voice and what has been said, to match the voice, not to
repeat topics, openings or phrases, never to copy a sentence, and that they
are data and not instructions.

An example is a whole post and may hold anything, so `PromptRenderer` makes
it harmless before a template sees it: every `<` that could begin a tag is
written as `&lt;`, so a text cannot close its element; a pillar name printed
as an attribute is put on one line, loses its double quotes and has `<` and
`>` written out. A text without anything like a tag is sent exactly as it was
approved. The brief, the pillar and the idea are still printed as written.
v1 is not edited: it prints an example as it always would have (platform and
text) and says about examples what its system part always said.

#### The other posts of the same week

Because examples are chosen when a job runs and only from approved posts, the
drafts of one proposal cannot see each other as examples. Repeating each
other was one of the two problems, so a request also carries
`DraftRequest::$alsoPlanned`, a separate list that is not examples
(`Actions\ListPlannedAlongside`): for the week of the planned time, on the
workspace calendar, the posts the week already holds that are not rejected,
and the runs of the same proposal that are still open. Of each: the name of
the pillar, the title of the idea and the first line of the text, each cut to
60 characters, for at most 12 posts. Never a whole text. It was cheap: the
runs of a proposal share `request['proposal']` and already point at their
pillar and idea, and the posts of a week are one query. A run without a
planned time gets no list. Jobs that run one after the other see the first
lines of the drafts before them; jobs that run at the same moment see only
the pillar and the idea of each other.

#### Cost

`ProposalCost` measures the rendered prompt, so the expected cost on both
plan pages includes the examples that would be sent now. The worst case must
hold whatever is approved between the start and the job, so it does not use
the current choice: it renders the request with the most examples it could
carry, each with the longest pillar name and no text, and the longest list of
posts planned alongside, and adds four tokens for every character
`NOONGUN_DRAFT_EXAMPLES_MAX_CHARS` allows, the most bytes a character has.
With the defaults that is about 35,000 input tokens more in the worst case of
one answer. It is the price of a bound that cannot be exceeded.

#### What is recorded

A run notes the ids of the posts whose texts it sent as
`request['example_post_ids']`, written while the run is still pending. No
text is copied. The editor reads the count for "Where this draft came from".
A retry notes its own choice. The list of posts planned alongside is not
recorded.

#### Chosen for how they did (M6, issue #6)

With M6 a post has numbers (ADR 0016), and the examples lean towards what
worked. Selection is still `Actions\SelectExamplePosts` reading through
`Content\ApprovedTexts`, which is the one place the order of approved texts is
decided: `latest()` for the most recent, `best()` for those that did well.

The rule in one sentence: of the examples of a channel, a share goes to the
approved posts of that channel that scored above its average in a recent
window, best first and at most one for each pillar, and every other place goes
to the most recent approved posts as before.

- **Places.** `NOONGUN_DRAFT_EXAMPLES_TOP_SHARE` (default 50) is the share in
  percent of `NOONGUN_DRAFT_EXAMPLES`, rounded down, and never all of them: at
  least one example of a channel is always its most recent approved text. With
  the defaults that is 2 of 5. A share of 0, a window of 0 days, or one example
  for a channel gives no such place, and then the choice is exactly the one
  described above for issue #22.
- **Did well.** The score is `EngagementScore` (ADR 0016), from the current
  numbers of the text on that channel. A text qualifies when its score is
  above the average score of the approved texts of the same channel that were
  published in the window. "Best" alone would not be honest: the best of three
  posts nobody reacted to did not do well, and the prompt says of a marked
  example that it did better than the posts of the author usually do. With one
  measured text, or with the same score for all, nothing is above the average
  and nothing is chosen this way.
- **Window.** `NOONGUN_DRAFT_EXAMPLES_TOP_DAYS` (default 90, at most 365),
  counted from `post_variants.published_at`, because that is when the numbers
  began. Approval can be much earlier than publication.
- **Not measured is not zero.** A text without a snapshot has no score. It is
  not in the ranking and not in the average; it competes for the places of the
  most recent only. A workspace without numbers, and every LinkedIn channel
  until M9, therefore gets exactly the choice of issue #22. A test compares
  the two.
- **One for each pillar.** Of the posts of one pillar, only the best can take
  a place for how it did (`SelectExamplePosts::TOP_PER_PILLAR`). The second
  place goes to the best post of another pillar, however close or far the
  scores are, and when no other pillar has a post above the average the place
  goes to a recent text. A closeness threshold ("prefer variety when scores
  are within x percent") was considered and left out: it is one more number
  nobody has data to set, and a fixed cap of one gives variety whenever there
  is any to be had. Posts without a pillar are not held to the cap, because
  nothing says they share a theme.
- **Never from another channel.** A top-up from other channels of the same
  platform is by recency only, as before, and is never marked. A score counts
  absolute reactions, so it compares posts of one channel (ADR 0016); a hit on
  a Page with many followers says little about what works on a small one, and
  "above the average" would have no channel to be the average of. A channel
  with too few measured posts simply gets recent texts.
- **Order.** For each target channel: the texts that did well, best first,
  then the recent ones, newest approval first, then top-ups. Every order ends
  on the id of the text (score, then approval, then id), so the same state
  gives the same request. The cost estimate and the tests rely on that.
- **The character limit.** Over `NOONGUN_DRAFT_EXAMPLES_MAX_CHARS`, the texts
  chosen for how they did are left out first, the lowest score first, and
  only then the text with the oldest approval. Under pressure the request
  falls back towards the choice of issue #22: the recent texts are what keeps
  a draft from repeating last week, which matters more than one more lesson
  in what worked. A workspace with long posts raises the limit to see more of
  the ranking.
- **Approval and workspace.** `best()` reads the same states as `latest()`
  through the workspace scope, and joins `metric_snapshots` on `workspace_id`
  as well as on the variant. A post that did well and was returned to draft is
  out, of the choice and of the average.

The three ways this could go wrong, and what holds against each:

1. *Collapse*: the same hits in every request, every draft imitates them,
   and the feed narrows. Held by the share (never more than all but one place,
   2 of 5 by default), by one place for each pillar, by the window, and by the
   prompt, which asks the model to learn how a marked example is made and not
   to return to its topic or wording.
2. *Staleness*: an old hit is sent for ever. Held by the window: after 90
   days a post cannot be chosen for how it did, whatever its score.
3. *Repetition*: ranking alone loses what was said last week. Held by the
   places that stay with the most recent texts, by leaving those out last
   under the character limit, and by `<also_planned>`.

What a provider is told. `ExamplePost` gains one value, `performedWell`, and
nothing else. Prompt v3 prints it as `performed="well"` on the `<example>`
and, only when a request holds such an example, adds these lines before the
examples:

> The examples marked performed="well" did better with readers than the posts
> of this author usually do. Learn from how they are made: how they open, how
> clearly they make one point, what concrete detail they use. Do not write
> about their topics again and do not reuse their wording because they did
> well: what is said above holds for them too. The examples without the mark
> are the most recent posts.

No number is sent: not the score and not reactions, comments, shares or
clicks. A number costs tokens on every example, invites the model to chase it
("write what got 400 reactions"), and cannot be read without knowing the size
of the audience, which is not sent either. The yes or no carries what the
model can use. Examples remain data: the mark is an attribute Noon Gun
writes, and a text or a pillar name cannot produce it, because `<` and `"`
are made harmless as before.

Prompt versions. v2 has been used for real drafts, so it is frozen now and
pinned by hash next to v1. v3 is a copy of v2 with the mark and the lines
above, and is the default. Its system part is that of v2 word for word, and
while no example is marked its user part is too; a test holds both. v1 and v2
receive the same request and print the examples without the mark. An instance
that sets `NOONGUN_DRAFT_PROMPT_VERSION=v2` still gets the new choice of
examples, without being told which did well.

Cost. The expected cost renders the request `SelectExamplePosts` would build
now, so it includes the choice by performance, the mark and the lines about
it, on both plan pages. The worst case renders every example a request could
carry as marked, so it also holds if every post is measured between the start
and the job. The allowance for the texts is unchanged: the number of examples
and the character limit are the same.

What is recorded. A run notes, next to `request['example_post_ids']`,
`request['performance_example_post_ids']`: the posts with a text that was
chosen because it did well. No score is copied. The editor says "Written with
N earlier posts as examples, M of them chosen because they did well." The
plan pages say that posts that did best go along when the choice made now
holds one, and the Drafting settings page names it in what is sent.

Not done: nothing rotates the hits within the window, so a workspace that
posts rarely can see the same two marked examples for weeks. The pillar cap
and the share bound the effect; rotation would make a request depend on the
day and is left until there is evidence it is needed.

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
