# 0016 - Insights: the metrics contract, polling and the engagement score

Date: 2026-11-26. Status: accepted.

## Context

M6 reads how published posts did, shows it for each post and each pillar, and
lets drafting learn from the posts that did well. The brief names a table
`metric_snapshots` (reactions, comments, shares, clicks, reach) and a method
`fetchMetrics(PostVariant $variant): MetricSnapshot` on `ChannelAdapter`.
ADR 0009 left that method out of the M3 contract and expected M6 to add it to
the interface.

What shapes the design, from `docs/platform-notes/meta.md` (2026-11-26) and
ADR 0007:

- Facebook gives reactions, comments and shares of a Page post with the
  permissions every connected Page has. Reach and clicks are post insights
  and need `read_insights`, which Noon Gun does not request today. The metric
  names of insights were replaced in 2025 and will change again.
- Facebook counts every post id as one call against a limit that is per Page
  token, also when ids are combined in one request.
- LinkedIn gives a self-serve app no numbers at all until M9.
- The same rate limit serves publishing. A post that cannot go out because
  metrics used up the calls would be a worse failure than a missing number.

## Decision

### The contract is an optional interface

Reading metrics is not added to `ChannelAdapter`. It is a second interface,
`App\Domain\Channels\Contracts\ReadsMetrics`, that an adapter implements when
its platform gives numbers, together with `ChannelCapabilities::$metrics`,
the list of numbers the platform can give. This replaces the plan in ADR 0009.

```php
interface ReadsMetrics
{
    /** @return list<Metric> */
    public function metricsFor(Channel $channel): array;

    public function metricCallsPerVariant(): int;

    /**
     * @param  list<PostVariant>  $variants
     * @return array<int, MetricReading> keyed by variant id
     */
    public function fetchMetrics(Channel $channel, array $variants): array;
}
```

Why not a method on `ChannelAdapter` with a capability flag: LinkedIn would
have to implement a method that can only throw or return nothing, and every
caller would have to remember to check the flag first. With the interface an
adapter without metrics has no such method, so nothing can call it, nothing
fails and no zero is invented. It is also how the codebase already expresses
what only some platforms do (`DescribesSetup`, `ExplainsMissingAccounts`).
Callers ask `ChannelManager::metricsReader()`, which gives the adapter only
when it implements the interface and lists at least one metric.

The method is batch-shaped, a channel and many variants, because the limits
are per channel and platforms offer batch reads. It returns readings and
writes nothing. A variant without an entry in the answer gave no reading this
time (its part of a batch failed, or was not finished); that is not an
error. A failure of the whole call is a `ChannelException` of the existing
kinds. A permission that is missing for some metrics only is not a failure:
those metrics are null.

A post that the platform no longer has is a third answer, next to a reading
and no reading: the entry is `MetricReading::gone()`, a reading with the flag
`gone` and no numbers. It was added when the Facebook adapter was built,
without changing a signature: `gone` is a last constructor argument that is
false unless named. An adapter uses it only when the platform said so, never
for a failure, and one that cannot tell the two apart leaves the variant out.
What the caller does with it is under "Polling".

`metricsFor()` says what the token of one channel can read. The capabilities
say what the platform has; a Page connected without `read_insights` has
fewer. The difference is what a page shows as "needs one more permission".

### Null is not zero

`MetricReading` holds one nullable whole number for each of the five metrics.
Null means the platform did not give the number: it has none, the permission
is missing, or its answer could not be trusted. Zero means the platform said
zero. The columns are nullable, sums are null when nothing was given, a row
without any number is never stored, and a post without numbers is reported as
not measured. Nothing turns one into the other.

Platform answers are untrusted. `MetricReading::fromPlatform()` keeps only
whole numbers from 0 to 10^12 and drops anything else to null; the
constructor refuses the rest, and the table has check constraints for the
same.

### Storage and retention

`metric_snapshots` has `workspace_id` with a composite foreign key to the
variant, `captured_at`, `checked_at`, the five numbers and `is_latest`.

- A row is added only when a number differs from the newest row of the
  variant. A reading that repeats it moves `checked_at` and adds nothing. So
  a row says "these were the numbers from `captured_at` until at least
  `checked_at`", and the rows of a variant are its history without repeats.
- `is_latest` marks the newest row, with a partial unique index, so the
  current numbers of many variants are one indexed read and can be joined
  without a subquery. `(post_variant_id, captured_at)` serves the history.
- A variant is read at most about 45 times with the default cadence, and
  never has more rows than it was read times. Nothing is pruned while the
  variant exists; rows go with the variant and the workspace.
- `post_variants.metrics_checked_at` records when the platform was last asked
  about a variant, whether or not it answered, so a variant without numbers
  is not asked again on every run.

### Polling

`noongun:poll-metrics` runs every ten minutes. For each workspace it queues
one `FetchMetricsJob` for each active channel whose platform reads metrics
and that has a variant due. The job decides again, under a lock for the
channel, what it reads: at most `NOONGUN_INSIGHTS_VARIANTS_PER_RUN` variants,
the ones that waited longest first, in one call to the adapter.

A variant is due by its age (`NOONGUN_INSIGHTS_CADENCE`, default
`24:60,504:1440`): every hour during its first day, when most of the
engagement happens, then once a day until it is three weeks old, then never.

The job only reads. Whatever the platform answers, it cannot change a post,
the outcome of a variant or the status of a channel. A failure is logged with
the safe message of the `ChannelException`, the channel is left alone for
`NOONGUN_INSIGHTS_PAUSE_MINUTES` or for as long as the platform asks, and the
next run tries again. The queue never retries the job. In particular a
"connect again" answer to a metrics call does not mark the channel: a missing
permission for insights must never stop a channel from publishing. Whether a
token is still good is decided by publishing and the token check
(`noongun:check-channel-tokens`, see "Added after review").

The payload is two ids. No token is in it or in a log line.

A pause that was set because the token was not accepted ends when the channel
is connected again (`ChannelConnected`, fired by `Channel::recordConnection()`
for a channel that is active and by `Channel::confirm()`). A wait the platform
asked for is kept apart and does not end that way.

**A post that is gone.** `post_variants.metrics_gone_count` counts the answers
in a row that a post is gone. At `NOONGUN_INSIGHTS_GONE_AFTER` (default 3)
`DueVariants` leaves the variant out, so it costs no more calls. More than one
answer is needed because platforms give the same answer for a deleted post
and for one the token cannot see at the moment; Facebook says so in the text
of its error. A reading sets the count back to zero, and so does connecting
the channel again, which is how a wrong conclusion is undone. The snapshots
the variant has are kept. Why a count and not a date: the question the job
asks is "how sure", and one column answers it; a page that wants to say "no
longer on Facebook" compares the count with `InsightsConfig::goneAfter()`.

**One job for each channel.** `FetchMetricsJob` is unique for its channel
(`ShouldBeUnique`): while one is queued or running, the scheduler does not
queue a second. So the queue holds at most one job for each channel however
long the workers are away, and when a platform is away the pause keeps the
scheduler from queuing at all. A job that was lost blocks its channel for at
most an hour. Two runs that do overlap are harmless as before: the lock lets
one of them read, and the other finds nothing due.

### Added after review (2026-11-27)

**A number is not lost because a platform stops giving it.** The first
version stored every reading as it came, so a reading without reach and
clicks replaced the stored ones with "not given". That happens for reasons
that say nothing about the post: Meta retires a metric name and the whole
insights read fails, `read_insights` is removed on Facebook and the answer
is empty, an answer is empty for a moment. `MetricSnapshot::record()` now
takes the metrics the channel can still read (`metricsFor()`), and for those
a number the variant has is carried forward when the reading lacks it. The
row notes this in `carried`, a map from metric to the time since which the
number is carried, and `carriedSince(Metric)` reads it; a page can say
"last given before" that time. A number becomes "not given" only when the
channel no longer lists the metric, which the token check records. Zero is
never carried over, and nothing is carried into a first row.

**One post cannot hold up a channel.** A post that answers with a
permission error is "no reading for this post"; the call fails as a missing
permission only when every post of the call answered that way. The job
hands the due variants to the adapter in parts
(`noongun.insights.variants_per_call`, 25, one request to Facebook) and
stores what each part returned before the next, so a failure keeps what was
read and has spent calls only for what was asked. After a failure that is
not the platform limiting requests, the variants of that part are marked as
asked, so they go to the back of the line instead of heading every run that
follows. `ChannelException::rateLimited()` tells the two apart: the platform
named a time to wait, or the adapter put `rate_limited` in the context.
Before another part is sent the job checks that one more call
(`noongun.publishing.timeout`) still fits in its time limit; the rest stays
due.

**The pause says why.** `ChannelPolling::pauseOf()` gives the kind of a pause
(`rate_limit`, `token`, `other`) and until when, so a page can tell the
truth about a channel whose numbers are not moving.

**The token check exists.** This record relied on "the token check" before
anything ran it. `noongun:check-channel-tokens` now runs every hour and
queues a `CheckChannelTokenJob` for each active channel that was not checked
in the last 24 hours, at most 50 in one run, on the insights queue. The job
calls `ChannelAdapter::refreshToken()` with the row of the channel locked,
so a connect at the same moment is not overwritten by the answer about the
token it replaced. It is how stored scopes follow what the platform grants,
and how a withdrawn token is found before a post fails.

A mark stops a channel from publishing, so it takes a definite answer: the
platform says the token is not valid (`is_valid` false, `active` false) or
has expired, names the permissions and one that publishing needs is not
among them, or refuses a refresh token as such. An answer that does not say
(`is_valid` or `active` missing or not a boolean), an outage, a rate limit,
an error about the app credentials and any exception leave the channel as it
was. Both adapters were tightened for this: before, a missing `is_valid` or
`active` counted as "not valid", and LinkedIn marked a channel on any
refusal of a refresh, also a wrong client secret. A check never makes a
channel active. When it finds the token good it fires
`ChannelTokenVerified`, which ends a pause of reading that was about the
token.

**Posts from before insights are read once.** A published variant that was
never asked about is due a single time when it is older than the cadence
reaches and at most `NOONGUN_INSIGHTS_BACKFILL_DAYS` (365) old. Such
variants come after the ones inside the cadence, newest first, within the
same size of a run and share of the rate limit, and are never due again:
`metrics_checked_at` is set whether or not the platform answered.

**The cadence is all or nothing.** One pair that cannot be read used to be
dropped, which turned `24:60,504:1440m` into "read for one day". Now the
whole default is used and a warning is logged once a day.

**Open for M8: the share is counted for a channel row, the limit is for a
Page.** `ChannelPolling` keys its counter by channel id. While a Page can be
connected in one workspace only that is the same thing. When the same Page
can be connected in several workspaces, each row would spend its own 20
percent of one limit; the counter must then be keyed by platform and
account id.

### Publishing comes first

- Reading counts its own calls for each channel and uses at most
  `NOONGUN_INSIGHTS_RATE_LIMIT_PERCENT` (default 20) of the limit the adapter
  declares, in the window of that limit. The adapter says what one variant
  costs (`metricCallsPerVariant()`). The rest is never touched by reading.
- When the platform says it is limiting requests, reading stops for that
  channel for the time the platform names, at least the configured pause.
- The job runs on its own queue (`insights`), so a backlog of readings never
  stands in front of a `PublishPostJob`. The queue has a supervisor of its
  own in `config/horizon.php`, `supervisor-insights`, with one process, in
  every environment that file defines. The first version listed both queues
  on one supervisor with automatic balancing. That consumed both, but
  balancing moves processes to the queue with the longer wait, so a backlog
  of readings could have left publishing with a single process. With two
  supervisors neither queue can take a process from the other.
- Publishing is not counted, not held back and not locked by any of this.

What this does not cover: the limit is the platform's, and Noon Gun does not
know how much of it other apps on the same Page use. The share is a margin,
not a guarantee.

### The engagement score

One number compares posts, defined in `EngagementScore` and nowhere else:

    score = reactions x 1 + comments x 2 + shares x 3 + clicks x 1

The weights are in `config('noongun.insights.score')`. A number that was not
given counts as nothing; when none of the four was given there is no score.
Reach is not part of it: a rate per reach cannot be compared with a post
whose reach is unknown, and most channels do not have reach. The score is
additive, so the score of a post is the sum over its variants, and the same
formula exists as an SQL expression for ranking in the database.

A score compares posts of one channel, or of channels that give the same
numbers. A channel without clicks scores lower for the same post.

### Reading the numbers

`App\Domain\Insights\Performance` is the read side: the current numbers of
variants, the history of one variant, totals for posts, a summary for each
pillar over a period, and the variants of given channels that did best in the
last N days. Each is one query, scoped to the current workspace.

### Drafting learns from the score

The examples a draft request carries lean towards the posts that did well:
`Content\ApprovedTexts::best()` ranks the approved texts of one channel by
the comparable score and gives those above the average of that channel in a
recent window. The comparable score (`EngagementScore::COMPARABLE_SQL`) is
reactions, comments and shares with the same weights, and never clicks or
reach: a channel can gain or lose `read_insights`, and posts older than the
cadence are frozen, so a score with clicks would rank posts by when the
permission was added. A post is compared only after
`NOONGUN_INSIGHTS_MIN_AGE_HOURS` (default 48), and nothing is said to have
done well until a channel has `NOONGUN_INSIGHTS_MIN_MEASURED_POSTS` (default
5) such posts. What that does, the limits it keeps and the three ways it
could go wrong (collapse, staleness, repetition) are recorded in ADR 0011,
"Chosen for how they did". It does not use `Performance::top()`, which ranks
what is public without asking for an approval and without the pillar of a
post. Null is not zero there either: a text
without numbers is not ranked and does not count towards the average. No
number is sent to a provider, only whether a text did well.

### What needs `read_insights`

Reach and clicks on Facebook. Without it both are null and everything else
works. To get them: add `read_insights` to the Meta app, add it to
`FACEBOOK_SCOPES`, and connect the Page again. It is not requested by default
because a self-hosted instance works without it and every permission is one
more thing to explain on the consent screen and, in Phase 3, in App Review.

When it is configured it is still optional for each Page. The adapter asks
for it in the dialog, but a Page that was not granted it connects all the
same, and the token check does not mark a Page that lacks it. Every other
configured permission stays required. Otherwise the step above would have
refused or stopped Pages for the sake of two numbers, against the rule that
metrics never get in the way of publishing.

### How Facebook is read

One request to the batch endpoint for every 25 posts: for each post a read of
the post (reactions, comments with replies, shares) and, with
`read_insights`, a read of its insights (clicks, reach). Meta counts each
read as a call, so `metricCallsPerVariant()` is 2. `ids=` is not used: it is
not documented, and the batch endpoint gives the same single round trip with
the token in the form body. What each answer of a read means, and which of
it rests on documentation and which on assumption, is in
`docs/platform-notes/meta.md` (2026-11-26, the adapter).

- A read that was not finished or failed for a moment gives no reading for
  that post. When it is the insights read, the post gets no reading at all
  this time, so a stored number is never replaced by "not given" because of
  an outage.
- Insights that are refused, empty or not permitted give null for reach and
  clicks and keep the rest.
- A refused token or a rate limit on any read fails the whole call.
- Only when nothing came back at all is a temporary failure a failure of
  the call, so the job pauses instead of marking every post as asked.

### LinkedIn

Deferred to M9 (ADR 0007). The adapter lists no metrics and does not
implement `ReadsMetrics`, so its channels are never polled and its posts are
shown as not measured.

## Consequences

- `ChannelAdapter` is unchanged, so no existing adapter or test double had to
  change. A platform gets metrics by implementing one more interface and
  listing them.
- The numbers of a post are as old as its last reading: up to an hour on the
  first day, up to a day later, and frozen after three weeks.
- A change that happens and reverts between two readings is not seen.
- A post that was deleted on the platform keeps its last numbers and is read
  three more times. A post that is reported as gone by mistake three times
  in a row is not read again until the channel is connected again.
- The share of the rate limit is counted in the cache store. If the cache is
  cleared, the count starts again; the platform's own refusal and the pause
  are what remains.
- Changing the weights changes every score at once, because scores are
  computed when read and not stored.
- "Top posts" ranks by an absolute count, so a post on a Page with many
  followers outranks a better post on a small one. Ranking is only meaningful
  within a channel or a platform, which is how drafting uses it.
