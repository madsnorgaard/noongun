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
time (deleted on the platform, or its part of a batch failed); that is not an
error. A failure of the whole call is a `ChannelException` of the existing
kinds. A permission that is missing for some metrics only is not a failure:
those metrics are null.

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
token is still good is decided by publishing and the token check.

The payload is two ids. No token is in it or in a log line.

### Publishing comes first

- Reading counts its own calls for each channel and uses at most
  `NOONGUN_INSIGHTS_RATE_LIMIT_PERCENT` (default 20) of the limit the adapter
  declares, in the window of that limit. The adapter says what one variant
  costs (`metricCallsPerVariant()`). The rest is never touched by reading.
- When the platform says it is limiting requests, reading stops for that
  channel for the time the platform names, at least the configured pause.
- The job runs on its own queue (`insights`), so a backlog of readings never
  stands in front of a `PublishPostJob`.
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
`EngagementScore::SQL` and gives those above the average of that channel in a
recent window. What that does, the limits it keeps and the three ways it
could go wrong (collapse, staleness, repetition) are recorded in ADR 0011,
"Chosen for how they did". It does not use `Performance::top()`, which ranks
what is public without asking for an approval and without the pillar of a
post; the formula is the same one. Null is not zero there either: a text
without numbers is not ranked and does not count towards the average. No
number is sent to a provider, only whether a text did well.

### What needs `read_insights`

Reach and clicks on Facebook. Without it both are null and everything else
works. To get them: add `read_insights` to the Meta app, add it to
`FACEBOOK_SCOPES`, and connect the Page again. It is not requested by default
because a self-hosted instance works without it and every permission is one
more thing to explain on the consent screen and, in Phase 3, in App Review.

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
- The share of the rate limit is counted in the cache store. If the cache is
  cleared, the count starts again; the platform's own refusal and the pause
  are what remains.
- Changing the weights changes every score at once, because scores are
  computed when read and not stored.
- "Top posts" ranks by an absolute count, so a post on a Page with many
  followers outranks a better post on a small one. Ranking is only meaningful
  within a channel or a platform, which is how drafting uses it.
