# Facebook metrics spike (M6)

A throwaway script that calls the real Graph API. It reads the numbers of one
Page post and prints every answer as Facebook sent it, so that the hand-written
fixtures in `tests/Fixtures/facebook/insights/` can be replaced by recorded
ones and the open points in `docs/platform-notes/meta.md` can be settled. No
application code depends on anything in this folder.

It only reads. Nothing on the Page is created, changed or deleted.

## Requirements

`bash`, `curl`, `jq`, `openssl`, `perl`.

## Secrets

- `.env` in this folder holds the app secret and a Page access token. It is
  gitignored. Start from `.env.example`.
- The token and `appsecret_proof` are passed to curl on stdin, never on the
  command line.
- Everything that is printed goes through a filter that replaces the token,
  the proof and the app secret with `[redacted]`, also inside the paging
  addresses that Facebook returns. Check the output once more before it is
  pasted anywhere.
- The script talks to `https://graph.facebook.com` only and follows no
  redirect.

## Run

```
cp .env.example .env            # fill in the version, the app secret and a Page token
./read-metrics.sh <post-id>     # the id of a post on that Page, as Noon Gun stores it
```

Use a post that has at least one reaction, one comment with a reply and one
share, so that every number is visible. Run it a second time for a post nobody
shared.

The script makes eight calls and prints the status line, the rate limit
headers and the body of each:

1. The post with the fields the adapter asks for.
2. The insights of the post, for clicks and reach.
3. The same without a period.
4. The post with the insights nested as a field. If this answers, one read
   for each post is enough.
5. The `ids=` form.
6. A metric name that Meta has retired, for the shape of that error.
7. A post id that does not exist, for the shape of the error that a deleted
   post gives.
8. The batch request the adapter really sends: the post, its insights and
   the post that does not exist.

Two things need a second token or Page and cannot be shown by one run:

- a Page token **without** `read_insights` (what do calls 2 and 8 answer?)
- a Page with **fewer than 100 likes** (an empty list, or an error?)

Record what was seen in `docs/platform-notes/meta.md` under the insights
section, and replace the fixtures. The end of the output lists the points.

When done, remove the token and the app secret from `.env`, or delete it.
