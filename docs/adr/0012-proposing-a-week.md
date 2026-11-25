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
   the workspace calendar. A time that has passed is left out. So is a time at
   which that channel already has a post in any state but `rejected`.
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
   state other than `rejected` uses. A post without a pillar takes one from
   the ideas without a pillar. Otherwise it has no idea.

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

### Checks, limits and the lock

`ProposeWeek` checks everything before the first run is opened, and it calls
no provider. The checks run inside one transaction that first takes
`SELECT ... FOR UPDATE` on the workspace row:

- setup: a current brief, a channel that can publish with a slot, and
  `DraftGenerators::availableFor()`;
- the plan is not empty and matches the fingerprint;
- no draft run of the workspace is `pending` or `running`;
- the runs opened in the last 24 hours plus this proposal stay within
  `runs_per_day`. Every run counts, failed ones too, because a failed run may
  have been paid for.

Two requests for one workspace therefore run one after the other, and the
second finds the runs of the first. The route is throttled as well, which
only slows a client down; the limits are the guard.

Each refusal is a `ProposalRefused` with a sentence for the person and a
reason code. The HTTP layer turns the code into a link to the page where it is
fixed, when that page exists on the instance.

The limits live in `noongun.drafting.limits`, read through `DraftingLimits`.

### A retry is a new run

`GenerateDraft` ends a run as `failed` on every failure, and ADR 0011 has no
way back from `failed`. `GenerateDraftJob` keeps that: when the failure is a
`DraftGenerationFailed` marked retryable, it opens a new pending run for the
same planned post, with `request.retry_of` and `request.attempt`, and queues
it after the backoff. The failed run keeps its reason and its tokens. At most
`max_attempts` runs exist for one planned post, and no retry is opened once
the workspace has used its runs for the day.

Invalid output is not retried by the job: the generator has already asked
twice (ADR 0011). A missing key, a refusal, a requester who may no longer
write and channels that can no longer publish end the run as failed, the last
three without a call to the provider.

The job has `$tries = 1` and carries three integers. A second delivery, a
second dispatch or a retry by hand finds the run taken by
`DraftRun::markRunning()` and does nothing.

### Stuck runs

`noongun:recover-stuck-draft-runs` marks a run failed when it has been
`running` for longer than `stuck_after_minutes` (never less than the time
limit of the job plus a minute), or `pending` for longer than that plus the
largest backoff. It never asks a provider again. A run whose worker died may
have been paid for, and a lost job has not: in both cases a person decides,
by proposing the week again, which fills exactly the times that are still
open.

### The cost ceiling

Before the start the page shows the most the proposal can cost by the price
table: posts x `max_attempts` x 2 answers, each with
`estimate_input_tokens` in and `max_tokens` out. It is a ceiling, not a
forecast, and the page says so. Without a price for the model nothing is
shown.

### Progress

The queue shows the runs of the latest proposal: while one is open, for an
hour after all were written, and for 24 hours when one failed. A run that was
tried again is shown as one line, by its newest run. While a run is open the
page asks for this one prop every five seconds with an Inertia partial reload
and stops when all are settled; the lists are loaded again only when a run
was settled.

What a model wrote about its draft (rationale, alternative text) and every
failure reason are shown as text.

## Consequences

- A week costs one call per post, more when channels do not share times.
- Proposing the same week twice is safe and is how failed or left out times
  are filled.
- A pillar's target is met over the week, counting posts written by hand that
  carry the pillar. Nothing sets a pillar on a hand-written post yet, so today
  only generated posts count.
- An idea is used once. Rejecting the post frees it again.
- Between the failure of a run and the opening of its retry, a few
  milliseconds, the workspace has no open run, and a second proposal could
  start. The daily limit still holds.
- When the daily limit stops a retry, the failed run shows the provider's
  reason, not the limit.
- A draft whose planned time passes before it is approved cannot be approved
  as it stands (ADR 0009): the person gives it a new time or clears it.
- The time limit of the job is twice the provider timeout plus 15 seconds,
  255 with the defaults, above the default `retry_after` of the queue (180).
  A second delivery does nothing, but is listed as a failed job. Raising
  `REDIS_QUEUE_RETRY_AFTER` to 300 avoids it; the default is left for a
  decision outside this record.
- The daily limit is a count of runs, not of money. Metering by cost belongs
  to M8.
- One proposal at a time per workspace also holds back a single draft asked
  for elsewhere while a proposal is at work, should such a feature come.
