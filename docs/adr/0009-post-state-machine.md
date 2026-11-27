# 0009 - Post state machine, and no metrics in the M3 adapter contract

Date: 2026-11-24. Status: accepted.

## Context

The brief (section 6) gives the post states
`draft -> approved -> scheduled -> publishing -> published`, with `failed` from
`publishing` and `rejected` from `draft`, and says only a human action moves a post
to `approved`. It does not say what separates `approved` from `scheduled`, how a
post relates to its variants when there are several, how a failed post is tried
again, or how a post gets back to `draft` for editing. M3 is built by several
tracks at once, so these have to be settled in one place first.

The brief (section 7) also lists `fetchMetrics()` on `ChannelAdapter`, returning a
`MetricSnapshot`. That model belongs to M6.

## Decision

### States

| State | Meaning |
|---|---|
| `draft` | Being written. The only state in which the post and its variants can be edited. May carry a planned `scheduled_at`. |
| `approved` | A human has signed off the content. `approved_by` and `approved_at` are set. The post is not in the publishing queue. |
| `scheduled` | Approved and committed to `scheduled_at`. The only state the scheduler picks up. |
| `publishing` | Claimed by the scheduler. Variants are being sent to their platforms. |
| `published` | Every variant is on its platform. Final. |
| `failed` | Publishing ended and at least one variant is not on its platform. |
| `rejected` | A human turned the draft down. Kept for reference. |

`approved` answers "may this be published", `scheduled` answers "when". A post can
be approved without a time (waiting for a slot) and cannot be scheduled without an
approval. When a draft that already has a planned time is approved, the approve
action schedules it in the same step, so the user presses one button.

### Transitions

`App\Domain\Content\PostState::transitions()` is the only place that lists them.

| From | To | Method on `Post` | Who |
|---|---|---|---|
| `draft` | `approved` | `approve(User $approver)` | Human only |
| `draft` | `rejected` | `reject()` | Human |
| `approved` | `scheduled` | `schedule(?DateTimeInterface $at)` | Human or system |
| `scheduled` | `approved` | `unschedule()` | Human |
| `scheduled` | `publishing` | `claimForPublishing(?DateTimeInterface $now)` | Scheduler |
| `publishing` | `published` or `failed` | `finishPublishing()` | Publish job |
| `failed` | `scheduled` | `retry(?DateTimeInterface $at)` | Human |
| `approved`, `scheduled`, `failed`, `rejected` | `draft` | `returnToDraft()` | Human |

The brief names only the forward path. `unschedule()`, `returnToDraft()` and
`retry()` are additions, needed to cancel, edit and try again.

### Only a human approves (project rule 1)

- `state`, `approved_by` and `approved_at` are not fillable, and saving a `Post`
  with any of them changed throws. They change only through the methods above.
- `approve()` is the only method that leads from `draft` towards a platform. It
  needs a saved `User` who is an owner or editor of the post's workspace. No job,
  command or default calls it.
- The database refuses a row that is past `draft` without `approved_at`, and a
  `draft` or `rejected` row that carries an approval.
- Variants can be created, changed or deleted only while the post is `draft`. To
  edit an approved post it goes back to `draft`, which erases the approval. What
  was approved is therefore exactly what is published.
- Every transition is one `UPDATE ... WHERE state = <expected>`. Two processes can
  never both win the same transition.

Rule 1 says a post reaches a platform "only from the approved state". With this
machine the path is `approved -> scheduled -> publishing`: nothing reaches
`publishing` without having been approved by a human, and the approval is still
on the row when it is published.

### Post and variants

The post is the unit that is approved, scheduled and claimed. A variant has no
state column:

- published: `external_id` and `published_at` are set
- failed: `error` is set and `external_id` is not
- waiting: neither

The outcome columns are written only by `PostVariant::markPublished(PublishResult)`
and `PostVariant::markFailed(string)`. `markPublished()` only matches a row whose
`external_id` is still empty, so a second result can never replace the first. A
published variant can never be edited or deleted.

`Post::finishPublishing()` moves the post to `published` when every variant is
published and to `failed` otherwise. A post with some variants out and some not is
`failed`; the published variants keep their permalinks.

### Retries

- Automatic retries happen inside `publishing`. `PublishPostJob` tries a variant
  again, with backoff, only when the adapter throws a `ChannelException` with
  `retryable` set. The post becomes `failed` when a variant fails for good or
  runs out of attempts (`noongun.publishing.max_attempts`).
- From `failed`, a human chooses `retry()`. The post goes back to `scheduled` with
  its approval, because its content cannot have changed. Variants that already
  have an `external_id` are skipped, so a retry never repeats a public post.
- If the content has to change, `returnToDraft()` and a new approval are required.

### Actions

Controllers call the classes in `App\Domain\Content\Actions`, never the
transition methods: `ApprovePost`, `RejectPost`, `SchedulePost`,
`UnschedulePost`, `ReturnPostToDraft` and `RetryPost`. Each takes the acting
user, allows owners and editors of the post's workspace, and checks what the
state machine cannot: the post has a variant, every variant that is still to
be sent has a channel that can publish and a text the platform accepts, and
the time is not in the past and not more than a year ahead.

`ApprovePost` is the only caller of `Post::approve()`. When the draft has a
planned time it schedules in the same transaction. A planned time that has
passed is refused instead of being published at once: the user picks a new
time or clears it.

### The claim

`claimForPublishing()` is one `UPDATE` that matches only a post that is
`scheduled` and whose `scheduled_at` has come. The time is part of the claim,
not only of the query before it, so a post that a human moved to a later time
after the scheduler read it is not sent now. Every claim starts a new round:
`posts.publish_round` goes up by one and `posts.publish_step` is set to zero.

### The publishing job

`PublishPostJob` is one chain of jobs per post and per round. A round is one
claim by the scheduler: the first, and one for each human retry. The job is
per post, not per variant, because the post is what is claimed and finished,
and a variant has no state column: only one owner of the round can know that
no variant will be tried again, which is what `finishPublishing()` requires.

- Every job names its round and a step. Before it does anything it takes the
  step with `Post::beginPublishingStep()`: one `UPDATE` that matches only
  while the post is `publishing`, approved, in that round, and at the step
  before. The database therefore lets each job run once. A job that is run
  again, by `queue:retry`, by the retry button in Horizon or by a queue that
  delivers twice, finds its step taken and does nothing. `tries` is 1 as
  well, but that alone does not stop a retry by hand.
- Each platform call is made under the lock of that variant, after reading
  the variant again inside the lock. A variant with an `external_id` is never
  sent.
- Just before the call, `PostVariant::beginAttempt()` sets
  `post_variants.outcome_unknown_since`. See "Unknown outcomes" below.
- A retry happens only after the adapter answered with a retryable failure,
  which by the adapter contract means the platform certainly made no post.
  The job then queues its successor: the next step, the next attempt, the
  backoff as delay (or the platform's `retryAfterSeconds` if that is longer)
  and the variants that already failed for good, so those are not sent again
  in the same round. Because every job runs once, that list cannot be lost
  by running an earlier job again. A platform that asks for a longer wait
  than the largest configured backoff fails the variant.
- One run sends at most `calls_per_job` variants and hands the rest to a
  successor with the next step and the same attempt. The time limit of a job
  is therefore at most `timeout x calls_per_job + 15` seconds, and the
  `retry_after` of the queue is set above that. If a run has both a variant
  to retry and variants it did not reach, they share one successor and one
  wait.
- A retryable failure writes its message to `error` while the post waits, so
  a variant of a `publishing` post can carry an error and still be tried.
- A failure that is not a `ChannelException` is treated as an unknown outcome:
  no retry, and the variant is marked with a text that says so.
- Publishing refuses to run when the cache store is not shared between
  workers (`array`, `file`, `null`), because the locks would mean nothing.
- `PostFailedToPublish` is fired when a post ends as `failed`.

### Unknown outcomes

`post_variants.outcome_unknown_since` is the durable record of "a platform
was called for this variant and nothing says the post was not made".

- Set by `beginAttempt()`, inside the lock, immediately before the call.
- Cleared by `markPublished()`, and by `markRefused()` when the adapter
  answers with a failure that does not carry `outcome_unknown` in its
  context: the platform refused, so it holds nothing from this attempt.
- Left in place when the adapter reports `outcome_unknown`, when it throws
  anything that is not a `ChannelException`, when the result cannot be
  written, and when the process dies. No code is needed for the last case,
  which is the point of writing the mark first.
- A mark left by an earlier attempt is not cleared by a later refusal. Only a
  recorded publish removes it.

While the mark is set on an unpublished variant
(`PostVariant::hasUnknownOutcome()`):

- no job sends the variant again by itself;
- the variant cannot be deleted or moved to another channel, `SaveDraft`
  keeps it when its channel is left out, and `DeletePost` keeps the post
  (ADR 0010). Its text can still be edited;
- a human can send it again through the normal path (return to draft and
  approve, or retry). The error text on the variant says to check the channel
  first. There is no separate "I have checked, it is not there" action in M3.

### Posts stuck in publishing

`posts.updated_at` is the sign of life of a publishing post. The claim sets
it and every run of the job renews it when it takes its step. A post that has
been silent for `stuck_after_minutes` plus the largest backoff is settled by
`noongun:recover-stuck-posts`, and a job that throws settles its own post in
the same way, under the locks of the unpublished variants:

- a variant with an unknown outcome gets a text that tells the user to check
  the channel;
- a variant that was never sent in this round says that nothing was sent;
- a variant the platform refused keeps that error.

`finishPublishing()` then makes the post `published` or `failed`. Recovery
never calls a platform and never puts a post back in the queue. A job that
arrives after its post was settled does nothing. A job that fails while one
of its variants is still locked (a time limit inside a platform call, or a
result that could not be written) cannot settle the post; the recovery does
it later.

### Metrics

`ChannelAdapter` in M3 has `platform`, `capabilities`, `authorizationUrl`,
`connect`, `publish`, `delete` and `refreshToken`. `fetchMetrics()` is added in M6
together with `MetricSnapshot`.

Changed by ADR 0016: metrics did not become a method of `ChannelAdapter`. They are
the optional interface `ReadsMetrics`.

## Consequences

- The scheduler needs one query: `scheduled` posts with `scheduled_at` in the past,
  per workspace, served by the index on `(workspace_id, state, scheduled_at)`.
- Editing an approved or scheduled post costs a second approval. That is intended.
- `approved_by` becomes empty if the approving user is deleted; `approved_at`
  stays, and the audit trail (M7) will hold the name.
- A worker that dies mid-publish leaves a post in `publishing` until the
  recovery settles it, which takes `stuck_after_minutes` plus the largest
  backoff (90 minutes with the defaults).
- If a platform accepts a post and the process dies before `markPublished()` is
  committed, or the platform does not answer, the variant has no
  `external_id`. This is now recorded: `outcome_unknown_since` was written
  before the call. Nothing sends such a variant again automatically, the
  record cannot be deleted or reset, and the post becomes `failed` with a
  warning to check the channel. What remains is the human: someone who
  retries without checking gets a duplicate. Closing that needs a lookup on
  the platform before a retry, or an explicit confirmation step, and is left
  open for M7 (hardening).
- If the result cannot be written, it is logged at once at `critical` level
  with the external id and permalink, before the job waits to try the write
  again. If the database is still away after those tries, that log line is
  the only place the external id exists.
- The job trusts two statements of the adapter: `retryable` means the
  platform certainly made no post, and a failure without `outcome_unknown`
  means the same. An adapter that gets either wrong can cause a duplicate
  (the first) or let the record of a possible post be cleared (the second).
- The locks live in the cache store. They guard against two jobs running at
  once; the `external_id` check guards against two jobs running one after the
  other. A cache store that is not shared by all workers, or that loses its
  locks while two copies of a job run, removes the first guard.
- Factories write later states directly to the table. That shortcut is for tests
  and seeders only.
- Adding `fetchMetrics()` in M6 changes the interface, so every adapter and the
  test fake must be updated then.
