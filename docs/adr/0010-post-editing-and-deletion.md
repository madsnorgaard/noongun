# 0010 - Editing, approving from the UI, and deleting posts

Date: 2026-11-24. Status: accepted.

## Context

ADR 0009 settles the states of a post and the actions that move it. The queue,
the calendar and the editor (M3) put those actions in front of a person, and
that raises questions ADR 0009 leaves open:

- Deleting a post cascades to its variants in the database, including
  published ones, and nothing in the model prevents it.
- `ApprovePost` checks that a post is ready and then approves it. An edit that
  arrives between the two steps, or just after the approval, would make the
  published text differ from the checked or approved one.
- The person who approves has read the post some time before pressing the
  button. Someone else may have edited it since.

## Decision

### Deleting

A post can be deleted when it is a `draft` or `rejected` and none of its
variants is on a platform. Everything else is kept. `DeletePost` enforces this
under a row lock; the delete button is only shown when the rule allows it.

A draft can hold a published variant when a partly published post was returned
to draft. That post is kept as well. So is a post with a variant whose outcome
is unknown (`PostVariant::hasUnknownOutcome()`, ADR 0009): a platform was
called for it and may hold the post, and the row with its error text is the
only trace of that. Deleting in Noon Gun never calls a
platform. Removing a public post, and removing its local record with it, is
left for a later milestone together with the audit trail.

### Writing

`SaveDraft` creates a draft or replaces the planned time and the per-channel
texts of an existing one. It works only on a draft and never changes a state.
A text may be empty or too long: unfinished work can be saved, and the
readiness check on approval decides what may go out. A variant that is
published is never changed or removed. A variant whose outcome is unknown can
be reworded but not removed: leaving its channel out of a save keeps the
variant, so the warning cannot be lost by removing the channel and adding it
again. The model refuses to delete such a variant or to move it to another
channel, whoever asks. A channel can only be
added to a post while it can publish.

### One lock for edits and state changes

`SaveDraft`, `DeletePost` and every request in `PostTransitionController` read
the post row with `SELECT ... FOR UPDATE` inside a transaction before doing
anything else. An approval therefore sees the texts it checked, and an edit
that waited for the lock finds an approved post and is refused.

The lock for the state changes is taken in the controller, because the action
classes are shared with other work in M3. Moving it into the actions is a
small follow-up and would protect callers outside the HTTP layer too.

### Approving what was read

Every page that offers approval carries a fingerprint of the post: a hash of
the planned time and of each variant's channel, text and link. The approve
request must send it back. If it no longer matches, the approval is refused
with "The post was changed after you opened it", and the page shows the new
content.

Approving from the queue is one request per click, or one request for the
drafts the user ticked. Each ticked draft is named with its own fingerprint
and approved, or refused, by itself. Nothing is ticked by default and there
is no "approve all".

### Calendar

The calendar shows every post that has a time, except rejected ones, at
`scheduled_at` in the workspace timezone. Drafts and approved posts with a
planned time are shown as plans, visibly different from scheduled posts. The
days are computed on the server, so the page needs no date library. Weeks
start on Monday.

## Consequences

- A post with a variant in doubt cannot be deleted, and that channel cannot
  be taken off it, until a publish for the variant is recorded. There is no
  way yet to say "I checked the Page and it is not there"; that belongs with
  the audit trail.
- A published post cannot be removed from the lists. The history grows; it is
  paged.
- Two people who edit the same draft at the same time: the last save wins.
  Only approval is guarded by the fingerprint.
- A local time that does not exist (the hour skipped when clocks go forward)
  is moved forward by an hour; a time that exists twice (clocks go back) is
  read as the second of the two, the one in standard time.
- The week start is fixed. It becomes a workspace setting when a market needs
  Sunday.
- `posts.scheduled_at` is the time on the calendar for published posts as
  well. After a retry it is the time of the retry.
