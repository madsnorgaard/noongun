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
| `scheduled` | `publishing` | `claimForPublishing()` | Scheduler |
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

### The publishing job

`PublishPostJob` is one job per post and per round. A round is one claim by
the scheduler: the first, and one for each human retry. The job is per post,
not per variant, because the post is what is claimed and finished, and a
variant has no state column: only one owner of the round can know that no
variant will be tried again, which is what `finishPublishing()` requires.

- The job acts only on a post that is `publishing`, in the round it was
  queued for (told apart by `scheduled_at`, which `retry()` renews), with
  `approved_at` set.
- Each platform call is made under the lock of that variant, after reading
  the variant again inside the lock. A variant with an `external_id` is never
  sent.
- The queue never runs the job twice by itself (`tries` is 1). A run that was
  cut off may have reached the platform, so nothing repeats it automatically.
- A retry happens only after the adapter answered with a retryable failure.
  The job then queues its own successor with the backoff as delay, or the
  platform's `retryAfterSeconds` if that is longer. The successor carries the
  attempt number and the variants that already failed for good, so those are
  not sent again in the same round. A platform that asks for a longer wait
  than the largest configured backoff fails the variant.
- A retryable failure writes its message to `error` while the post waits, so
  a variant of a `publishing` post can carry an error and still be tried.
- A failure that is not a `ChannelException` is treated as an unknown outcome:
  no retry, and the variant is marked with a text that says so.
- `PostFailedToPublish` is fired when a post ends as `failed`.

### Posts stuck in publishing

`posts.updated_at` is the sign of life of a publishing post. The claim sets
it and the job renews it at the start of every attempt. A post that has been
silent for `stuck_after_minutes` plus the largest backoff is settled by
`noongun:recover-stuck-posts`, and a job that throws settles its own post in
the same way: under the locks of the unpublished variants, those variants are
marked as failed with a text that tells the user to check the channel, and
`finishPublishing()` makes the post `published` or `failed`. Recovery never
calls a platform and never puts a post back in the queue. A job that arrives
after its post was settled does nothing.

### Metrics

`ChannelAdapter` in M3 has `platform`, `capabilities`, `authorizationUrl`,
`connect`, `publish`, `delete` and `refreshToken`. `fetchMetrics()` is added in M6
together with `MetricSnapshot`.

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
  committed, the variant looks unpublished. The per-variant lock and the
  `external_id` check cannot see this case. Nothing sends such a variant again
  automatically: the post becomes `failed` with a warning to check the channel.
  A human who retries without checking gets a duplicate. Closing this needs a
  record of the attempt that is written before the call, or a lookup on the
  platform, and is left open for M7 (hardening).
- The same holds when an adapter reports a failure as retryable although the
  platform did accept the post, for example a timeout while reading the
  response. The job trusts `retryable`. Adapters must set it only when the
  platform certainly did not create the post.
- The locks live in the cache store. They guard against two jobs running at
  once; the `external_id` check guards against two jobs running one after the
  other. A cache store that is not shared by all workers, or that loses its
  locks while two copies of a job run, removes the first guard.
- Factories write later states directly to the table. That shortcut is for tests
  and seeders only.
- Adding `fetchMetrics()` in M6 changes the interface, so every adapter and the
  test fake must be updated then.
