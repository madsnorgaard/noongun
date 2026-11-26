# 0012 - Proposing a week

Date: 2026-11-25. Status: accepted.

## Context

ADR 0011 fixes what one generation is: one request, one run, one post, and
that post is a draft. "Propose a week" (brief section 9) turns the brief, the
pillars and the slots into a week of such drafts. The brief does not say how
pillars are spread over slots, what happens to channels that share a time, how
a failed generation is tried again, or what keeps a proposal from spending
more than intended. Those are settled here.

## Decision

### Nothing but drafts

A proposal opens draft runs and queues `GenerateDraftJob`, which calls
`GenerateDraft` (ADR 0011). What comes out is a draft with a planned time.
Approving is the action of M3: a person, one post, with the content
fingerprint (ADR 0010). There is no "approve all" and no route under
`proposals` that changes the state of a post.
`tests/Feature/Drafting/ProposedWeekStaysDraftsTest.php` runs a whole proposal
and holds that line, next to the two tripwire tests.

### The planning rule

`PlanWeek` works out the plan and creates nothing. In this order:

1. **Times.** Every slot of a channel that can publish gives one time in the
   week, through `Slot::occurrenceInWeekOf()`. A week is Monday to Sunday on
   the workspace calendar. A time is left out when
   - it has passed;
   - it is closer than `lead_minutes` (60 by default): its draft could not be
     drafted, read and approved before it passes, and a draft whose time has
     passed cannot be approved as it stands (ADR 0009);
   - that channel already has a post at that instant in any state but
     `rejected`;
   - a succeeded run of an earlier proposal for the same week names the slot
     (`request.slot_ids`, `request.week`) and its post still exists in a
     state other than `rejected`, wherever the time of that post is now.
     Moving a drafted post does not open its slot again.
2. **Posts.** Channels whose open slots fall on the same instant share one
   post with a text for each of them. The posts are put in time order, and
   only the earliest `posts_per_proposal` are kept.
3. **Pillars.** A pillar needs its `weekly_target` minus the posts the week
   already holds for it (any state but `rejected`, planned inside the week).
   The pillars take turns, largest target first, then by name, then by id,
   one post per turn, until every need is met. That sequence is laid over the
   posts in time order. More needs than posts: the end of the sequence is
   dropped and the plan names the pillars that come up short. Fewer: the
   remaining posts have no pillar and are drafted from the brief alone. No
   pillar with a target: every post is.
4. **Ideas.** A post takes the oldest idea of its pillar that no post in a
   state other than `rejected` uses, and when there is none, or the post has
   no pillar, the oldest such idea without a pillar. An idea of another
   pillar is never borrowed. Otherwise the post has no idea.

Taking turns spreads the pillars over the week instead of filling Monday to
Wednesday with the largest one. The rule has no randomness, so the page can
show the plan before anything is spent, and a test can state it.

### One post for channels that share a time

The post is the unit of approval (ADR 0009), and a `DraftRequest` asks for one
post written once for each target channel (ADR 0011). Two channels that post
on Monday at nine get one post with two texts: one call to the provider, one
thing to read and approve. Channels with different times get separate posts.

When one of the channels already has a post at that time, the proposed post
covers the others only.

### What was shown is what runs

The page that shows the plan carries `WeekPlan::fingerprint()`: a hash of the
week and of each post's time, channels, pillar and idea. The request that
starts the proposal sends it back, and `ProposeWeek` plans again and refuses
when it differs. The planned time is stored on each run
(`request.planned_at`) and the job uses that instant, not the slot as it is
when the job runs: a slot edited in between does not move a draft that was
already asked for.

The same holds for who pays. A run records the provider, the model, the
prompt version and the scope of the key when it is opened, and the person saw
a cost for exactly that. `GenerateDraft` resolves the context again when the
run starts and compares: if any of the four differs, because a key was added
or removed or the operator changed the model, the run fails with
`DraftingContextChanged` before a provider is called.

### Checks, limits and the lock

`ProposeWeek` checks everything before the first run is opened, and it calls
no provider. (Since issue #20 the checks, the lock and the queueing are in
`StartDraftRuns`, which `ProposeWeek` calls; see "Drafting one post from an
idea".) The checks run inside one transaction that first takes
`SELECT ... FOR UPDATE` on the workspace row:

- setup: a current brief, a channel that can publish with a slot, and
  `DraftGenerators::availableFor()`;
- the plan is not empty and matches the fingerprint;
- no draft run of the workspace is `pending` or `running`;
- the runs of the workspace that count, plus this proposal, stay within
  `runs_per_day`;
- when the key of the instance would pay, the runs of all workspaces on that
  key that count, plus this proposal, stay within `instance_runs_per_day`.

Two requests for one workspace therefore run one after the other, and the
second finds the runs of the first. The routes are throttled as well, which
only slows a client down; the limits are the guard.

`DraftBudget` is the one place that decides which runs count: opened in the
last 24 hours, and either still open or with tokens recorded. A run that
ended without tokens spent nothing (it could not be queued, no worker picked
it up, the provider turned it away for a wrong key or an unknown model) and
counts for nothing, so trying a key a few times never uses up the day. A run
that failed after the provider answered counts, because it was paid for.

The count on the instance key crosses workspaces. It is read through
`InstanceKeyRun`, a second model on `draft_runs` that is scoped to
`credential_scope = instance` instead of to a workspace, in the way
`InstanceLlmCredential` is for the key itself (ADR 0011). It cannot be saved
or deleted, and only `DraftBudget` uses it, only to count.

Each refusal is a `ProposalRefused` with a sentence for the person and a
reason code. The HTTP layer turns the code into a link to the page where it is
fixed. On a self-hosted instance a refusal for a limit names the setting,
because the reader runs the instance; a tenant is told to try again later.

The limits live in `noongun.drafting.limits`, read through `DraftingLimits`.

### A retry is a new run

`GenerateDraft` ends a run as `failed` on every failure, and ADR 0011 has no
way back from `failed`. `GenerateDraftJob` keeps that: when the failure is a
`DraftGenerationFailed` marked retryable, it opens a new pending run for the
same planned post, with `request.retry_of` and `request.attempt`, and queues
it after the backoff. The failed run keeps its reason and its tokens. At most
`max_attempts` runs exist for one planned post, and no retry is opened once
the workspace, or the key of the instance when it pays, has used its runs for
the day.

Marking the run failed and opening its retry are one step. `GenerateDraft`
takes the lock on the workspace row, marks the run failed and calls back into
the job in the same transaction, and the job opens the retry there, counting
the limits under the lock a proposal counts them under. A workspace with a
retry on its way therefore always has an open run, and no proposal can start
in between. The job is queued after the commit; if that fails, the retry is
marked failed at once instead of being left pending.

Invalid output is not retried by the job: the generator has already asked
twice (ADR 0011). A missing key, a changed context, a requester who may no
longer write and channels that can no longer publish end the run as failed
without a call to the provider.

When the provider answered and the post then cannot be saved, the run fails
with the usage and the cost of that answer: it was paid for.

The job has `$tries = 1` and carries three integers. A second delivery, a
second dispatch or a retry by hand finds the run taken by
`DraftRun::markRunning()` and does nothing.

### Runs that do not finish

`noongun:recover-stuck-draft-runs` marks a run failed when it has been
`running` for longer than `stuck_after_minutes` (6 by default, never less
than the time limit of the job plus a minute), or `pending` for longer than
that plus the largest backoff. It never asks a provider again. A run whose
worker died may have been paid for, and a lost job has not: in both cases a
person decides, by proposing the week again, which fills exactly the times
that are still open.

The page does not leave the person waiting for that. Once a pending run is
older than two minutes (plus its backoff, for a retry), or a running one is
older than a job may take, the progress card says that it is waiting longer
than expected and to check the queue, and offers "Stop waiting" to owners and
editors. `StopOpenRuns` takes the workspace lock and marks every open run of
the workspace failed through `markFailed()`. It calls no provider and queues
nothing, and it does not depend on the scheduler. A job that arrives later
finds its run settled. A worker that was in the middle of a stopped run
cannot record its result: the answer is discarded.

### What members read and what is logged

A failure reason on a run and a refusal on the plan page are read by every
member of the workspace. `DraftGeneratorNotAvailable` names classes,
configuration paths and environment variables, and `DraftingNotAllowed` names
ids, so neither message is shown: members read a fixed sentence, and the
detail is logged. `GenerateDraftJob` logs every failed run with the class of
the failure, its message cleaned of credentials and cut to 500 characters,
and the operator detail a `DraftingException` carries.

### Cost

Before the start the page shows two figures by the price table, both from
`ProposalCost`, which renders the prompt each planned post would be sent and
measures it:

- **Expected**: one answer for each post. The prompt counts one token for
  three bytes, the answer `expected_output_tokens`.
- **Worst case**: posts x `max_attempts` x 2 answers, each with `max_tokens`
  out and the prompt at one token for every byte of prompt and schema, plus
  1024 tokens for what a provider wraps around them. A token is never shorter
  than a byte, so this bound holds for any brief.

An earlier version showed only a ceiling with an assumed prompt size. It was
about a hundred times what a week costs, and not a ceiling for a long brief.

After the start the progress card sums the tokens and the estimated cost of
every run of the proposal, failed attempts included, and the editor shows on
a drafted post what its own run used.

### Progress

The queue shows the runs of the latest proposal: while one is open, for an
hour after all were drafted, and for 24 hours when one failed. A run that was
tried again is shown as one line, by its newest run. While a run is open the
page asks for this one prop every five seconds with an Inertia partial reload
and stops when all are settled; the lists are loaded again only when a run
was settled.

What a model wrote about its draft (rationale, alternative text) and every
failure reason are shown as text.

### Words

The thing is a draft, the act is drafting, and the time on a draft is its
planned time, on every page that proposing a week adds or changes. The queue
of M3 keeps "firing time" for posts that are approved.

### Drafting one post from an idea

Added on 2026-11-26 (issue #20), after the first real use: the owner wanted
one post from one idea for one Page, and the only way was to add the idea and
propose a week.

"Draft a post" on an idea opens one draft run for one post, with a text for
each channel the person ticks. It is not a second way to draft:

- **One way to start.** What `ProposeWeek` did around its plan (the actor
  check, the lock on the workspace row, the setup checks, the limits, opening
  the runs, queueing the jobs, closing a run that cannot be queued) moved to
  `StartDraftRuns`. `ProposeWeek` and `DraftFromIdea` each hand it a plan and
  nothing else. A week needs a slot on a channel that can publish; one post
  does not, and that is the only check that differs.
- **The run.** `request.source` is `idea`. It has `request.proposal` like any
  run, so the progress card, the retry, the recovery and "Stop waiting" find
  it without knowing about it. It names no week and no slot. The job, the
  generator, the cost and the limits are untouched.
- **The plan.** `PlanIdeaDraft` reads the channels that can publish, the
  channels that were asked for and the time, and creates nothing. The pillar
  is the pillar of the idea and is never read from the request. The page
  carries the choice in its address, so the cost and the checks it shows are
  worked out by the server for exactly what is ticked;
  `ProposalCost::ofOnePost()` is the figure of one planned post of a week
  with those channels.
- **The planned time** is optional, as on a post written by hand. A time that
  is given must be further away than `lead_minutes`, for the reason a slot
  time that close is left out of a week, and must pass the rule for a
  scheduled time: not more than a year ahead, and not after the access of a
  chosen channel ends. That rule moved to `PostPreconditions::timeProblems()`
  so that it can be asked for channels before a post exists. A post written
  by hand can still be saved with any time; the lead time is a rule of
  drafting, because a drafted post has to be drafted, read and approved
  before its time.
- **One press, one run.** `IdeaDraftPlan::fingerprint()` covers the idea, its
  pillar, the channels, the time, how many posts the idea is used in and the
  newest draft run for the idea. A second press finds the run of the first
  under the lock and is refused as "still being drafted"; one from a page
  that was opened before the first draft arrived, or failed, is refused as
  changed.
- **Used ideas and slots need no new rule.** The draft carries the idea and
  the pillar through `Post::attributeTo()`, as a proposed post does, so
  `PlanWeek` does not take the idea again and counts the post towards the
  weekly target of its pillar. A post at the slot time of a channel already
  keeps that time out of a plan, whoever made the post, so a draft from an
  idea with such a time fills the slot. It is held by its time and not by
  `request.slot_ids`: moved to another time it opens the slot again, as a
  post written by hand does, and unlike a post of a proposed week.
- **Words.** The reasons a failed run gives end in "propose the week again".
  For a run from an idea `DraftRun::markFailed()` puts "draft the post again
  from the Ideas page" there, in the one place every failure passes. The
  progress card is headed "Drafting a post from an idea".

## Consequences

- A week costs one call per post, more when channels do not share times.
- Proposing the same week twice is safe and is how failed or left out times
  are filled. A drafted post that was moved keeps its slot; rejecting or
  deleting it opens the slot.
- A pillar's target is met over the week, counting posts written by hand that
  carry the pillar. Nothing sets a pillar on a hand-written post yet, so today
  only drafted posts count.
- An idea is used once. Rejecting the post frees it again. The Ideas page
  counts every post of an idea, also rejected ones, so "used in" there can be
  higher than what planning treats as used.
- The count on the instance key is checked under the lock of one workspace.
  Two workspaces that start at the same instant can each pass it, so the cap
  can be exceeded by one proposal. It is a brake on the shared key, not an
  exact meter; metering by cost belongs to M8.
- A run that fails without a reported usage does not count towards a limit.
  The exception, added in M5, is a call that timed out: the provider may have
  received and billed it, and such runs are retried, so a brief that makes a
  model think past the timeout could otherwise spend on the instance key
  without end. The generator records a timeout as an attempt with no known
  tokens (`input_tokens` and `output_tokens` of 0), which makes the run count
  as one that reached a provider. A provider that could not be reached at
  all (the name does not resolve, the connection is refused) still records
  nothing. The line between the two is the text of the HTTP client's error,
  "timed out" or cURL error 28; a connection that timed out before anything
  was sent is counted too, which errs on the side of the limit. The cost
  estimate of such a run is zero and is a lower bound.
- When a limit stops a retry, the failed run shows the provider's reason, not
  the limit.
- A run that is stopped, or given up by the recovery, while its worker is
  still with the provider loses the usage of that answer: a settled run
  cannot be written to.
- A draft whose planned time passes before it is approved cannot be approved
  as it stands (ADR 0009): the person gives it a new time or clears it. The
  lead time makes that rare, not impossible.
- The time limit of the job is twice the provider timeout plus 15 seconds,
  255 with the defaults. The `retry_after` of the Redis and database queues is
  300 by default and has to be raised together with `NOONGUN_DRAFT_TIMEOUT`;
  a test holds the defaults in that order.
- `GenerateDraft::handle()` takes an optional callback that runs in the
  transaction that marks a failure. It is the one way to react to a failure
  without leaving a gap, and it must not call a provider.
- One proposal at a time per workspace also holds back a single draft asked
  for on an idea while a proposal is at work, and the other way round (see
  "Drafting one post from an idea").
