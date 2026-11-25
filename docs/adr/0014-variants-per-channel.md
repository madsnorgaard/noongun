# 0014 - One idea, one variant per channel

Date: 2026-11-25. Status: proposed.

## Context

M5 adds a second platform and asks for two things (issue #5): one idea
produces one variant per channel, each within that channel's capabilities, and
the editor shows and edits variants side by side.

Most of the machinery was already there when M5 started:

- A post has one variant per channel, one `scheduled_at`, one approval and one
  publishing round (ADR 0009). `PublishPostJob` sends each variant once under
  its own lock and a retry skips what is out.
- A `DraftRequest` names every target channel with its limits, and a
  `DraftResult` exists only when it holds a text for each of them, none for
  any other, none empty and none over its limit (ADR 0011).
- `PlanWeek` gives channels whose open slots fall on the same instant one post
  (ADR 0012).
- The editor saves one text and one link per channel and adds a channel with a
  copy of the first text (ADR 0010).

What was missing:

- Nothing told a member that the same slot time is what makes channels share a
  post. With slots at different times each channel got its own post on its own
  idea, with no word on why.
- The v1 prompt asked for a text per channel and said little about how they
  differ, so two channels tended to get the same text.
- The editor stacked the texts. It had no way to bring one text into another
  after the first tick, did not say in a column that its channel could no
  longer publish, and did not say what one approval covers.

The brief leaves open whether the variants of a post share a time or each
keeps the time of its channel's slot, and the design guide approves each
channel version by itself where the system approves the post (`docs/design.md`,
"Where the guide and the system differ").

## Decision

### When channels share a post

**Channels with a slot at the same time share one post; slots at different
times get separate posts.**

"The same time" is the same instant: the same weekday and time on the
workspace clock. An hour apart on the same day is two posts. This is the rule
of ADR 0012, kept, and now said to the member in three places before anything
is spent:

- the Slots page states it once there is more than one channel;
- the plan page marks each post that is written for several channels;
- the plan page counts the posts that reach one channel only although the plan
  drafts for more than one (`WeekPlan::postsForOneChannel()`), and points at
  the slots.

A shared post is one `DraftRequest`, one draft run and one cost, whatever the
number of channels.

Joining slots of the same day was considered and not done. The post has one
time, so one of the channels would go out at a time its member did not set,
and "the same day" needs a second rule for which time wins. The same instant
needs no second rule.

### One time for all variants of a post

The variants of a post share the planned time and the firing time of the post.
The post is what the scheduler claims: the claim, the round, the step and the
recovery of a stuck post are all per post (ADR 0009), and that is what makes a
duplicate public post impossible to produce by running a job twice. A time per
variant would need a claim per variant and a post that is half scheduled. That
is a different state machine, and nothing in M5 needs it.

A member who wants the same idea at different times on two channels writes or
drafts two posts.

### One approval for the post

Approval stays per post, by a person, with the fingerprint of every variant
(ADR 0009, ADR 0010). Nothing new approves anything. What changed is that the
page says what the approval covers: above the approve button the editor lists
each channel that is still to be sent and the one time they fire, or that
nothing is sent until the post has a firing time. The list is not shown while
the page holds changes that are not saved, because the approval covers what is
saved.

Approving each variant by itself, as the guide draws it, is an open question
for the owner and is not built.

### Each text within its own channel

Nothing in the validation changed, because it already held this line:

- Drafting: `DraftResult::fromArray()` checks each text against the
  capabilities of its own target. One text over its limit, one missing, one
  empty or one for a channel that was not asked for fails the whole run. A
  shared post is never created with a channel left out by the model.
- A channel that cannot publish when the job runs is left out of the request,
  and the draft is written for the channels that are left (ADR 0012).
- Writing by hand: a text may be saved empty or too long, and the readiness
  check on approval refuses the post (ADR 0010). The check is per variant:
  the error names the channel.

What blocks an approval, per variant: an empty text, a text over the limit of
its platform, a channel that cannot publish, and a platform this instance has
no adapter for. The editor now shows these in the column they belong to:

- the count turns red and reads "N over the limit of M", with one line that
  the post cannot be approved until the text fits;
- a channel that is disconnected, waiting, expired or without an adapter shows
  why, says that the post cannot be approved while it has a text for that
  channel, and offers "Take it off this post". The other texts are not
  touched. `PostPresenter::channelOptions()` now gives the missing adapter as
  the problem of a channel, where it gave none.

### Writing one post for several channels by hand

One text, then diverge. A channel that is ticked starts from the first text
and its link (as before). Each column has "Copy text from" for the other
columns that hold a text: a button when there is one, a list when there are
more. Copying replaces the text only, never the link, and asks first when the
column already holds a different text. After that every text is edited by
itself; `SaveDraft` writes each variant from its own input and leaves a
variant alone when its input did not change.

### Side by side

Each channel is a column: name, platform, the copy control, the text with a
live count against the limit of that channel's platform, and the link. Columns
stand next to each other when each can be at least 20rem wide and stack
otherwise. With more than one text the status and the actions move under the
columns until the screen is very wide, so that two texts fit next to each
other on a laptop. Posts that are not drafts show their texts the same way,
read-only.

`PostVariant` and `SaveDraft` are not changed. A platform that needs more
fields on a variant (a link title, a description, a thumbnail) adds them to
the variant and to the column; the column has the link field as its last
block for that reason.

### The prompt

`resources/prompts/draft/v2` replaces v1 as the default
(`NOONGUN_DRAFT_PROMPT_VERSION`). v1 is not edited: runs name it (ADR 0011).
v2 receives the same data, `DraftRequest::toArray()`, and says:

- every text of a request says the same thing, and each is written for its
  own channel from its first line, never pasted and never cut down from
  another;
- the rules of a platform are printed inside the channel they are for;
- what the capabilities mean: the limit in characters, a link that is shown
  as a card and is therefore left out of the text, a channel that takes no
  images;
- hashtags, mentions and emoji only where the rules of the channel or the
  brief ask for them.

Conventions of a platform stay data: they come from the capabilities of the
adapter and from the voice rules of the workspace. The template names no
platform. `PromptRendererTest` now runs its checks for every version that has
a folder and fails when a folder is added without being listed.

## Consequences

- Slot times decide what is shared. A member with Facebook at nine and
  LinkedIn at noon gets two posts on two ideas until they give both the same
  time. The plan page says so; it does not do it for them.
- One idea cannot go out at different times on different channels from one
  post.
- A shared post is approved, scheduled, rejected and returned to draft as a
  whole. When one channel fails, the post is `failed`, the other keeps its
  permalink, and a retry sends only what is missing (ADR 0009).
- A channel that is disconnected after drafting blocks the approval of every
  post that has a text for it until it is connected again or taken off those
  posts, one post at a time.
- A channel added to a drafted post starts from a copy of the first text.
  Nothing drafts a text for one more channel of an existing post.
- The link of a drafted post is the same on every variant (ADR 0011). A
  member can change it per channel in the editor.
- Drafts made with v2 cannot be compared one to one with drafts made with v1.
  The version is on each run.
- An instance that sets `NOONGUN_DRAFT_PROMPT_VERSION=v1` keeps v1.
