# Facebook fixtures for reading metrics

Fixtures for `FacebookAdapter::fetchMetrics()`. **None of them is a recorded
answer.** Every file was written by hand from Meta's documentation as reviewed
on 2026-11-26 (see the two entries of that day about reading the numbers of a
post in `docs/platform-notes/meta.md`). Ids and trace ids are invented, and no
value in this folder is, or was ever, a real credential. The `access_token` in
the paging addresses of `post_insights.json` is the text
`must-never-be-requested`.

When real answers have been recorded with
`scripts/spike/facebook/read-metrics.sh`, replace each file (with ids and
tokens swapped for placeholder values) and delete its line below.

## The files

"Documented" means the shape is shown or described on a page of Meta's
documentation. "Assumed" means it is not, and is how the API is known to
answer.

| File | Holds | Documented | Assumed |
|---|---|---|---|
| `post_engagement.json` | `GET /{post-id}?fields=reactions.summary(total_count).limit(0),comments.filter(stream).summary(true).limit(0),shares` | `reactions.summary.total_count`; `comments.summary` with `total_count` and `order`; `shares` as a struct with `count`. | That `limit(0)` gives an empty `data` next to the summary. `can_comment` inside the summary. That the totals are readable with `pages_read_engagement`. |
| `post_engagement_no_shares.json` | The same for a post nobody shared. | - | That `shares` is left out, not sent as `{"count": 0}`. The adapter reads the absence as zero. |
| `post_engagement_malformed.json` | Numbers that cannot be right: negative, a fraction, a digit string. | - | Invented. It proves that only whole numbers from zero up are kept. |
| `post_insights.json` | `GET /{post-id}/insights?metric=post_clicks,post_total_media_view_unique&period=lifetime` | An entry with `name`, `period`, `values[0].value`, `title`, `description` and `id`; `paging` with `previous` and `next`. | The texts of `title` and `description`. |
| `post_insights_empty.json` | Insights without an entry. | An empty dataset is what a token without `read_insights` gets. | That a Page with fewer than 100 likes gets the same. |
| `post_insights_malformed.json` | Entries the adapter must skip: another period, a value that is a breakdown, no value, a metric that was not asked for. | - | Invented. |
| `error_insights_permission.json` | A permission error on the insights read. | Error 200 is listed for the insights edge. | The message. Whether it is ever sent, since the guide says an empty dataset is. |
| `error_invalid_metric.json` | A metric name that Meta has retired. | Error 100, "(#100) The value must be a valid insights metric". | - |
| `error_post_gone.json` | A post that was deleted on Facebook. | - | All of it: error 100 with subcode 33 and this message. |
| `batch_mixed.json` | A whole answer of the batch endpoint for three posts with insights: one read in full, one gone, one whose insights read was not finished. | The envelope: a list in the order of the requests, each entry with `code`, `headers` (name and value) and `body` as a JSON string; `null` for a request that was not finished. | That a deleted post answers with HTTP 400 inside the batch. |

Errors that are not specific to metrics (rate limit, expired token, service
error, a missing permission) are in the parent folder and are reused. The
other batch answers in the tests are put together from the files above by
`fakeGraphMetrics()` in `tests/Support/facebook.php`, in the same envelope.

## What a first real call must confirm

Each point is an assumption the adapter or its tests rely on. The numbers
refer to "What the documentation does not say" in the platform notes.

| Assumption | Note |
|---|---|
| The totals of reactions and comments can be read with `pages_read_engagement`. | 5 |
| `shares` is absent, not `{"count": 0}`, for a post nobody shared. | 6 |
| A deleted post answers with error 100 and subcode 33, error 803 or HTTP 404, for that post only. | 3 |
| A Page with fewer than 100 likes gets an empty `data` or an error for insights, and still gets its reactions, comments and shares. | 4 |
| A token without `read_insights` gets an empty `data` or a permission error for insights, and the post itself can still be read. | - |
| The batch endpoint accepts a version in its path and `appsecret_proof` as a top-level parameter. | 9 |
| The usage header of a read that was turned away is on its entry or on the batch. | 8 |
| The batch request itself is not counted as a call. | 7 |

Two things that would make reading cheaper are tried by the spike script and
are not used by the adapter: the `ids=` form (note 1) and insights nested as a
field of the post (note 2).
