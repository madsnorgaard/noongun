# Facebook fixtures for reading metrics

Fixtures for `FacebookAdapter::fetchMetrics()` go in this folder. There are none
yet: the method is not built (M6, issue #6).

Every file added here before a real call has been recorded is **written by
hand** from Meta's documentation as reviewed on 2026-11-26 (see "reading the
numbers of a post" in `docs/platform-notes/meta.md`), and must be listed below
with the assumption it carries. Ids and trace ids are invented, and no value in
this folder is, or was ever, a real credential. When a real answer has been
recorded with a script under `scripts/spike/`, replace the file (with ids and
tokens swapped for placeholder values) and delete its line.

## Layout

| File | Holds |
|---|---|
| `post_engagement.json` | The post with `reactions.summary(total_count)`, `comments.summary(true)` and `shares`. |
| `post_engagement_no_shares.json` | The same for a post nobody shared. |
| `post_insights.json` | `GET /{post-id}/insights` with the clicks and reach metrics, period `lifetime`. |
| `post_insights_empty.json` | The insights of a post on a Page with fewer than 100 likes. |
| `batch_*.json` | Answers of the batch endpoint: all succeeded, one post deleted, one request not finished (`null`). |
| `error_insights_permission.json` | The insights call without the insights permission. |
| `error_invalid_metric.json` | A metric name that Meta has deprecated. |
| `error_post_gone.json` | A post that was deleted on Facebook. |

Errors that are not specific to metrics (rate limit, expired token, service
error) are in the parent folder and are reused.

## What a first real call must confirm

Each point is an assumption the adapter or its tests will rely on. The numbers
refer to "Not confirmed" in the platform notes.

| Assumption | Note |
|---|---|
| The totals of reactions and comments can be read with `pages_read_engagement`. | 1 |
| `shares` is absent, not `{"count": 0}`, for a post nobody shared, and absent means zero. | - |
| Several posts can be read in one request, with `ids=` or the batch endpoint, and each id counts as one call. | 2 |
| An insights entry holds `name`, `period` and `values[0].value`. | 3 |
| A Page with fewer than 100 likes gets an empty `data` or an error for insights, and still gets its reactions, comments and shares. | 4 |
| Without the insights permission the insights call fails with a permission error, and the post itself can still be read. | 5 |
| A deleted post answers with an error for that post only. | 7 |
