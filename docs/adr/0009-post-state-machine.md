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
- A worker that dies mid-publish leaves a post in `publishing`. Track B must detect
  this (`noongun.publishing.stuck_after_minutes`) and finish such posts.
- If a platform accepts a post and the process dies before `markPublished()`, the
  variant looks unpublished. The per-variant lock and the `external_id` check
  cannot see this case. It is left open for M7 (hardening).
- Factories write later states directly to the table. That shortcut is for tests
  and seeders only.
- Adding `fetchMetrics()` in M6 changes the interface, so every adapter and the
  test fake must be updated then.
